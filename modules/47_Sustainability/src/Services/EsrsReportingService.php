<?php

declare(strict_types=1);

namespace Modules\Sustainability\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Sustainability\Models\EsgEsrsDatapoint;
use Modules\Sustainability\Models\EsgEsrsDatapointValue;
use Modules\Sustainability\Models\EsgMaterialityAssessment;
use Modules\Sustainability\Models\EsgReportingPeriod;
use Modules\Sustainability\Models\EsgSupplierDataRequest;
use Modules\Sustainability\Support\Decimal;
use Modules\Sustainability\Support\EsrsCatalog;

/**
 * CSRD/ESRS reporting package of a period: the catalog of data points, their values and review status, and the
 * completeness check. The double materiality assessment decides which standards are required: ESRS 2 is always required,
 * a topic standard (E1-E5, S1-S4, G1) only when its topic is material. Without an approved assessment every mandatory
 * data point is required and the check reports the missing assessment.
 */
final readonly class EsrsReportingService
{
    public function __construct(
        private InventoryService $inventory,
        private Scope3Service $scope3,
        private SustainabilityLedger $ledger,
    ) {}

    /**
     * Installs the default catalog (idempotent by code).
     *
     * @return int number of data points created
     */
    public function installCatalog(string $tenantId): int
    {
        $created = 0;

        foreach (EsrsCatalog::defaults() as $index => [$code, $standard, $disclosure, $name, $type, $unit, $mandatory, $auto]) {
            if (EsgEsrsDatapoint::query()->where('tenant_id', $tenantId)->where('code', $code)->exists()) {
                continue;
            }

            EsgEsrsDatapoint::create([
                'tenant_id' => $tenantId,
                'code' => $code,
                'standard' => $standard,
                'disclosure' => $disclosure,
                'name' => $name,
                'data_type' => $type,
                'unit' => $unit,
                'is_mandatory' => $mandatory,
                'auto_source' => $auto,
                'sort_order' => ($index + 1) * 10,
                'is_active' => true,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createDatapoint(string $tenantId, array $data): EsgEsrsDatapoint
    {
        return EsgEsrsDatapoint::create([
            'tenant_id' => $tenantId,
            'code' => $data['code'],
            'standard' => $data['standard'],
            'disclosure' => $data['disclosure'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'data_type' => $data['data_type'] ?? 'number',
            'unit' => $data['unit'] ?? null,
            'is_mandatory' => $data['is_mandatory'] ?? true,
            'auto_source' => $data['auto_source'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDatapoint(EsgEsrsDatapoint $datapoint, array $data): EsgEsrsDatapoint
    {
        $datapoint->update(array_intersect_key($data, array_flip(['standard', 'disclosure', 'name', 'description', 'data_type', 'unit', 'is_mandatory', 'auto_source', 'sort_order', 'is_active'])));

        return $datapoint->refresh();
    }

    /**
     * The catalog with the value of the period and whether each data point is required by the materiality assessment.
     *
     * @return array<string, mixed>
     */
    public function packageOf(EsgReportingPeriod $period, ?string $standard = null): array
    {
        $tenantId = (string) $period->tenant_id;
        $materiality = $this->materialityOf($period);
        $values = $this->valuesOf($period)->keyBy('datapoint_id');

        $rows = [];
        foreach ($this->activeDatapoints($tenantId, $standard) as $datapoint) {
            $value = $values->get($datapoint->id);
            $rows[] = [
                'datapoint' => $datapoint,
                'value' => $value,
                'is_required' => $this->isRequired($datapoint, $materiality),
            ];
        }

        return ['rows' => $rows, 'materiality' => $materiality];
    }

    /**
     * Records the values of several data points in one transaction. Approving a value is reserved to the caller that
     * passes $mayApprove (the controller checks the approve policy).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<EsgEsrsDatapointValue>
     */
    public function recordValues(EsgReportingPeriod $period, array $lines, ?string $actorId, bool $mayApprove): array
    {
        return DB::transaction(function () use ($period, $lines, $actorId, $mayApprove): array {
            $saved = [];

            foreach ($lines as $index => $line) {
                $datapoint = EsgEsrsDatapoint::query()->where('tenant_id', $period->tenant_id)->find($line['datapoint_id']);
                if ($datapoint === null) {
                    throw ValidationException::withMessages(["values.{$index}.datapoint_id" => [__('sustainability_errors.esrs.datapoint_unknown')]]);
                }

                $saved[] = $this->recordOne($period, $datapoint, $line, $actorId, $mayApprove, $index);
            }

            return $saved;
        });
    }

    /**
     * Fills the data points that have an auto source from the carbon inventory and the period. Approved values are kept.
     *
     * @return array{updated: int, skipped_approved: int}
     */
    public function syncFromInventory(EsgReportingPeriod $period, ?string $actorId): array
    {
        $report = $this->inventory->report($period);
        $sources = [
            'inventory.scope_1_tco2e' => Decimal::round(Decimal::div($report['totals']['scope_1_kg'], '1000'), 4),
            'inventory.scope_2_tco2e' => Decimal::round(Decimal::div($report['totals']['scope_2_kg'], '1000'), 4),
            'inventory.scope_3_tco2e' => Decimal::round(Decimal::div($report['totals']['scope_3_kg'], '1000'), 4),
            'inventory.total_tco2e' => Decimal::round($report['totals']['total_tco2e'], 4),
            'inventory.intensity_revenue' => $report['intensity']['tco2e_per_million_revenue'],
            'period.employees_fte' => $period->employees_fte,
        ];

        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($period, $sources, $actorId, &$updated, &$skipped): void {
            $datapoints = EsgEsrsDatapoint::query()->where('tenant_id', $period->tenant_id)->where('is_active', true)->whereNotNull('auto_source')->get();

            foreach ($datapoints as $datapoint) {
                $number = $sources[(string) $datapoint->auto_source] ?? null;
                if ($number === null) {
                    continue;
                }

                $existing = EsgEsrsDatapointValue::query()->where('reporting_period_id', $period->id)->where('datapoint_id', $datapoint->id)->first();
                if ($existing !== null && $existing->status === EsgEsrsDatapointValue::Approved) {
                    $skipped++;

                    continue;
                }

                EsgEsrsDatapointValue::updateOrCreate(
                    ['reporting_period_id' => $period->id, 'datapoint_id' => $datapoint->id],
                    ['tenant_id' => $period->tenant_id, 'status' => EsgEsrsDatapointValue::Draft, 'value_number' => Decimal::round((string) $number, 4), 'source' => 'inventory', 'omission_reason' => null, 'updated_by' => $actorId],
                );
                $updated++;
            }
        });

        $this->ledger->record('ESG_ESRS_SYNCED', $period, ['updated' => $updated, 'skipped_approved' => $skipped]);

        return ['updated' => $updated, 'skipped_approved' => $skipped];
    }

    /**
     * Completeness of the reporting package: per standard, the required data points that are approved (or omitted with a
     * reason), the missing ones, and the blocking issues and warnings around them.
     *
     * @return array<string, mixed>
     */
    public function completeness(EsgReportingPeriod $period): array
    {
        $package = $this->packageOf($period);
        $materiality = $package['materiality'];
        $standards = [];
        $missing = [];

        foreach ($package['rows'] as $row) {
            /** @var EsgEsrsDatapoint $datapoint */
            $datapoint = $row['datapoint'];
            $standard = $standards[$datapoint->standard] ?? ['standard' => $datapoint->standard, 'is_material' => $this->standardIsRequired($datapoint->standard, $materiality), 'required' => 0, 'complete' => 0];

            if ($row['is_required']) {
                $standard['required']++;
                $reason = $this->missingReason($row['value']);

                if ($reason === null) {
                    $standard['complete']++;
                } else {
                    $missing[] = ['datapoint_id' => $datapoint->id, 'code' => $datapoint->code, 'standard' => $datapoint->standard, 'name' => $datapoint->name, 'reason' => $reason];
                }
            }

            $standards[$datapoint->standard] = $standard;
        }

        $standardRows = [];
        $required = 0;
        $complete = 0;
        foreach ($standards as $standard) {
            $required += $standard['required'];
            $complete += $standard['complete'];
            $standardRows[] = [...$standard, 'percent' => $standard['required'] === 0 ? '100.00' : Decimal::round(Decimal::div(Decimal::mul((string) $standard['complete'], '100'), (string) $standard['required']), 2)];
        }

        $issues = [];
        $warnings = [];

        if ($package['rows'] === []) {
            $issues[] = 'catalog_empty';
        }
        if ($materiality['assessment_id'] === null) {
            $issues[] = 'materiality_assessment_missing';
        }
        foreach ($materiality['unassessed_standards'] as $standard) {
            $issues[] = "topic_not_assessed:{$standard}";
        }
        if ($missing !== []) {
            $issues[] = 'datapoints_missing';
        }

        $withoutData = $this->scope3->categoriesWithoutData($period);
        if ($withoutData !== []) {
            $warnings[] = 'scope3_categories_without_data';
        }
        $openRequests = EsgSupplierDataRequest::query()->where('reporting_period_id', $period->id)->whereIn('status', [EsgSupplierDataRequest::Requested, EsgSupplierDataRequest::Received])->count();
        if ($openRequests > 0) {
            $warnings[] = 'supplier_requests_open';
        }
        if ($period->isOpen()) {
            $warnings[] = 'period_open';
        }

        return [
            'period_id' => $period->id,
            'is_complete' => $issues === [],
            'percent' => $required === 0 ? '0.00' : Decimal::round(Decimal::div(Decimal::mul((string) $complete, '100'), (string) $required), 2),
            'required' => $required,
            'complete' => $complete,
            'materiality_assessment_id' => $materiality['assessment_id'],
            'standards' => $standardRows,
            'missing' => $missing,
            'issues' => $issues,
            'warnings' => $warnings,
            'scope3_categories_without_data' => $withoutData,
            'open_supplier_requests' => $openRequests,
        ];
    }

    /**
     * The approved assessment of the period (or the latest approved one without a period) and the standards it makes
     * material.
     *
     * @return array{assessment_id: string|null, material_standards: list<string>, assessed_standards: list<string>, unassessed_standards: list<string>}
     */
    public function materialityOf(EsgReportingPeriod $period): array
    {
        $assessment = EsgMaterialityAssessment::query()
            ->where('tenant_id', $period->tenant_id)
            ->where('status', EsgMaterialityAssessment::Approved)
            ->where(static fn ($query) => $query->where('reporting_period_id', $period->id)->orWhereNull('reporting_period_id'))
            ->orderByRaw('case when reporting_period_id is null then 1 else 0 end')
            ->orderByDesc('approved_at')
            ->with('topics')
            ->first();

        if ($assessment === null) {
            return ['assessment_id' => null, 'material_standards' => [], 'assessed_standards' => [], 'unassessed_standards' => []];
        }

        $assessed = $assessment->topics->pluck('esrs_code')->unique()->values()->all();
        $material = $assessment->topics->where('is_material', true)->pluck('esrs_code')->unique()->values()->all();
        $unassessed = array_values(array_diff(array_filter(EsgEsrsDatapoint::Standards, static fn (string $standard): bool => $standard !== 'ESRS2'), $assessed));

        return ['assessment_id' => (string) $assessment->id, 'material_standards' => $material, 'assessed_standards' => $assessed, 'unassessed_standards' => $unassessed];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function recordOne(EsgReportingPeriod $period, EsgEsrsDatapoint $datapoint, array $line, ?string $actorId, bool $mayApprove, int $index): EsgEsrsDatapointValue
    {
        $status = (string) ($line['status'] ?? EsgEsrsDatapointValue::Draft);
        $field = "values.{$index}";

        if ($status === EsgEsrsDatapointValue::Approved && ! $mayApprove) {
            throw ValidationException::withMessages(["{$field}.status" => [__('sustainability_errors.esrs.approval_reserved')]]);
        }

        $number = $line['value_number'] ?? null;
        $text = $line['value_text'] ?? null;
        $boolean = $line['value_boolean'] ?? null;
        $reason = $line['omission_reason'] ?? null;

        if ($status === EsgEsrsDatapointValue::NotApplicable) {
            if ($reason === null || trim((string) $reason) === '') {
                throw ValidationException::withMessages(["{$field}.omission_reason" => [__('sustainability_errors.esrs.omission_reason_required')]]);
            }
            $number = $text = $boolean = null;
        } elseif ($status !== EsgEsrsDatapointValue::Draft) {
            $provided = match ($datapoint->data_type) {
                'number' => $number !== null,
                'boolean' => $boolean !== null,
                default => $text !== null && trim((string) $text) !== '',
            };
            if (! $provided) {
                throw ValidationException::withMessages(["{$field}.value_{$datapoint->data_type}" => [__('sustainability_errors.esrs.value_required', ['code' => $datapoint->code])]]);
            }
        }

        $value = EsgEsrsDatapointValue::updateOrCreate(
            ['reporting_period_id' => $period->id, 'datapoint_id' => $datapoint->id],
            [
                'tenant_id' => $period->tenant_id,
                'status' => $status,
                'value_number' => $number === null ? null : Decimal::round((string) $number, 4),
                'value_text' => $text,
                'value_boolean' => $boolean,
                'source' => 'manual',
                'omission_reason' => $status === EsgEsrsDatapointValue::NotApplicable ? $reason : null,
                'notes' => $line['notes'] ?? null,
                'updated_by' => $actorId,
            ],
        );

        if ($status === EsgEsrsDatapointValue::Approved) {
            $this->ledger->record('ESG_ESRS_VALUE_APPROVED', $value, ['code' => $datapoint->code]);
        }

        return $value;
    }

    /**
     * @return Collection<int, EsgEsrsDatapoint>
     */
    private function activeDatapoints(string $tenantId, ?string $standard): Collection
    {
        return EsgEsrsDatapoint::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->when($standard !== null, static fn ($query) => $query->where('standard', $standard))
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();
    }

    /**
     * @return Collection<int, EsgEsrsDatapointValue>
     */
    private function valuesOf(EsgReportingPeriod $period): Collection
    {
        return EsgEsrsDatapointValue::query()->where('reporting_period_id', $period->id)->get();
    }

    /**
     * @param  array{assessment_id: string|null, material_standards: list<string>, assessed_standards: list<string>, unassessed_standards: list<string>}  $materiality
     */
    private function isRequired(EsgEsrsDatapoint $datapoint, array $materiality): bool
    {
        return $datapoint->is_mandatory && $this->standardIsRequired($datapoint->standard, $materiality);
    }

    /**
     * @param  array{assessment_id: string|null, material_standards: list<string>, assessed_standards: list<string>, unassessed_standards: list<string>}  $materiality
     */
    private function standardIsRequired(string $standard, array $materiality): bool
    {
        if ($standard === 'ESRS2' || $materiality['assessment_id'] === null) {
            return true;
        }

        return in_array($standard, $materiality['material_standards'], true) || in_array($standard, $materiality['unassessed_standards'], true);
    }

    private function missingReason(?EsgEsrsDatapointValue $value): ?string
    {
        if ($value === null) {
            return 'no_value';
        }
        if ($value->status === EsgEsrsDatapointValue::NotApplicable) {
            return $value->omission_reason !== null && trim($value->omission_reason) !== '' ? null : 'omission_reason_missing';
        }
        if ($value->status !== EsgEsrsDatapointValue::Approved) {
            return 'not_approved';
        }

        return $value->hasValue() ? null : 'no_value';
    }
}
