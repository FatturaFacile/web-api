<?php

declare(strict_types=1);

namespace Modules\SwissVat\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\SwissVat\Domain\Enums\BezugsteuerStatus;
use Modules\SwissVat\Domain\Enums\SwissVatFrequency;
use Modules\SwissVat\Domain\Enums\SwissVatReturnStatus;
use Modules\SwissVat\Domain\ValueObjects\SuisseTaxValidationResult;
use Modules\SwissVat\Exceptions\SwissVatException;
use Modules\SwissVat\Models\SwissVatBezugsteuerEntry;
use Modules\SwissVat\Models\SwissVatInputTaxAdjustment;
use Modules\SwissVat\Models\SwissVatReturn;
use Modules\SwissVat\Models\SwissVatXmlExport;

/**
 * Orchestrates Swiss VAT period return lifecycle: calculate, reconcile, validate XSD, finalize, and export SuisseTax XML.
 */
final class SwissVatReturnWorkflowService
{
    public function __construct(
        private readonly SwissVatConfigService $configService,
        private readonly SwissVatReconciliationService $reconciliation,
        private readonly SuisseTaxXmlBuilder $xmlBuilder,
        private readonly SuisseTaxXsdValidator $xsdValidator,
    ) {}

    /**
     * @return Collection<int, SwissVatReturn>
     */
    public function list(string $tenantId, ?int $fiscalYear = null): Collection
    {
        return SwissVatReturn::query()
            ->where('tenant_id', $tenantId)
            ->when($fiscalYear !== null, static fn ($q) => $q->where('fiscal_year', $fiscalYear))
            ->orderByDesc('period_to')
            ->get();
    }

    public function find(string $tenantId, string $id): SwissVatReturn
    {
        $vatReturn = SwissVatReturn::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($id)
            ->first();

        if ($vatReturn === null) {
            throw SwissVatException::notFound('return_not_found');
        }

        return $vatReturn;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function calculate(
        string $tenantId,
        ?string $userId,
        int $fiscalYear,
        string $periodType,
        int $periodNumber,
        array $overrides = [],
    ): SwissVatReturn {
        [$from, $to, $label] = $this->resolvePeriodRange($fiscalYear, $periodType, $periodNumber);

        return DB::transaction(function () use ($tenantId, $userId, $fiscalYear, $periodType, $periodNumber, $from, $to, $label, $overrides): SwissVatReturn {
            $existing = SwissVatReturn::withTrashed()
                ->where('tenant_id', $tenantId)
                ->where('fiscal_year', $fiscalYear)
                ->where('period_type', $periodType)
                ->where('period_number', $periodNumber)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->isLocked()) {
                throw SwissVatException::locked('return_already_finalized', ['period' => $label]);
            }

            $config = $this->configService->getOrDefault($tenantId);
            $reconciled = $this->reconciliation->reconcilePeriod($tenantId, $from, $to, $config, $overrides);
            $computed = $reconciled['computed'];
            $quadratura = $reconciled['quadratura'];

            $attributes = [
                'tenant_id' => $tenantId,
                'fiscal_year' => $fiscalYear,
                'period_type' => $periodType,
                'period_number' => $periodNumber,
                'period_label' => $label,
                'period_from' => $from->toDateString(),
                'period_to' => $to->toDateString(),
                'accounting_method' => (string) ($overrides['accounting_method'] ?? $config->accounting_method),
                'consideration_basis' => (string) ($overrides['consideration_basis'] ?? $config->consideration_basis),
                'currency' => $config->default_currency ?: 'CHF',
                'status' => $quadratura->passed ? SwissVatReturnStatus::Validated->value : SwissVatReturnStatus::Calculated->value,
                'box_200_total_turnover' => $computed['box_200'],
                'box_289_total_deductions' => $computed['box_289'],
                'box_299_taxable_turnover' => $computed['box_299'],
                'box_399_total_output_tax' => $computed['box_399'],
                'box_479_total_input_tax' => $computed['box_479'],
                'box_500_payable_amount' => $computed['box_500'],
                'box_510_credit_amount' => $computed['box_510'],
                'gross_turnover' => $computed['gross_turnover'],
                'invoiced_vat_total' => $computed['invoiced_vat_total'],
                'forfeited_input_vat' => $computed['forfeited_input_vat'],
                'sss_revenue_adjustment' => $computed['sss_revenue_adjustment'],
                'bezugsteuer_base' => $computed['bezugsteuer_base'],
                'bezugsteuer_tax' => $computed['bezugsteuer_tax'],
                'adjustments_box_410' => $computed['adjustments_box_410'],
                'adjustments_box_415' => $computed['adjustments_box_415'],
                'adjustments_box_420' => $computed['adjustments_box_420'],
                'afc_boxes' => $computed['afc_boxes'],
                'quadratura_passed' => $quadratura->passed,
                'quadratura_report' => $quadratura->toArray(),
                'reconciliation_lines' => $reconciled['reconciliation_lines'],
                'updated_by' => $userId,
            ];

            if ($existing === null) {
                $vatReturn = SwissVatReturn::create($attributes + ['created_by' => $userId]);
            } else {
                $existing->fill($attributes);
                $existing->deleted_at = null;
                $existing->save();
                $vatReturn = $existing;
            }

            if ($reconciled['bezugsteuer_ids'] !== []) {
                SwissVatBezugsteuerEntry::query()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('id', $reconciled['bezugsteuer_ids'])
                    ->update(['swiss_vat_return_id' => $vatReturn->id]);
            }

            if ($reconciled['adjustment_ids'] !== []) {
                SwissVatInputTaxAdjustment::query()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('id', $reconciled['adjustment_ids'])
                    ->update(['swiss_vat_return_id' => $vatReturn->id]);
            }

            return $vatReturn;
        });
    }

    public function finalize(string $tenantId, string $id, ?string $userId): SwissVatReturn
    {
        return DB::transaction(function () use ($tenantId, $id, $userId): SwissVatReturn {
            $vatReturn = $this->find($tenantId, $id);

            if ($vatReturn->isLocked()) {
                throw SwissVatException::locked('return_already_finalized', ['period' => $vatReturn->period_label]);
            }

            if (! $vatReturn->quadratura_passed) {
                throw SwissVatException::invalid('quadratura_failed');
            }

            $vatReturn->update([
                'status' => SwissVatReturnStatus::Finalized->value,
                'finalized_at' => now(),
                'finalized_by' => $userId,
                'updated_by' => $userId,
            ]);

            SwissVatBezugsteuerEntry::query()
                ->where('tenant_id', $tenantId)
                ->where('swiss_vat_return_id', $vatReturn->id)
                ->where('status', BezugsteuerStatus::Confirmed->value)
                ->update(['status' => BezugsteuerStatus::Declared->value]);

            SwissVatInputTaxAdjustment::query()
                ->where('tenant_id', $tenantId)
                ->where('swiss_vat_return_id', $vatReturn->id)
                ->where('status', 'approved')
                ->update(['status' => 'applied']);

            return $vatReturn;
        });
    }

    public function validateXml(string $tenantId, string $id): SuisseTaxValidationResult
    {
        $vatReturn = $this->find($tenantId, $id);
        $tenant = Tenant::query()->findOrFail($tenantId);
        $config = $this->configService->getOrDefault($tenantId);
        $xml = $this->xmlBuilder->build($vatReturn, $tenant, $config);
        $quadratura = $this->reconciliation->evaluateQuadratura(
            boxes: $vatReturn->afc_boxes,
            method: (string) $vatReturn->accounting_method,
            uidMwst: $config->uid_mwst ?: ($tenant->uid_number ?: $tenant->vat_number),
            pendingBezugsteuerCount: 0,
            periodTo: $vatReturn->period_to,
        );

        return $this->xsdValidator->validate($xml, $vatReturn, $config, $quadratura);
    }

    /**
     * Generates, validates against eCH-0217 XSD & quadratura, and persists a SuisseTax XML export audit record.
     * Throws SwissVatException (422) if validation fails, while persisting the failed audit attempt for traceability.
     */
    public function exportSuisseTaxXml(string $tenantId, string $id, ?string $userId): SwissVatXmlExport
    {
        $vatReturn = $this->find($tenantId, $id);
        $tenant = Tenant::query()->findOrFail($tenantId);
        $config = $this->configService->getOrDefault($tenantId);
        $xml = $this->xmlBuilder->build($vatReturn, $tenant, $config);
        $quadratura = $this->reconciliation->evaluateQuadratura(
            boxes: $vatReturn->afc_boxes,
            method: (string) $vatReturn->accounting_method,
            uidMwst: $config->uid_mwst ?: ($tenant->uid_number ?: $tenant->vat_number),
            pendingBezugsteuerCount: 0,
            periodTo: $vatReturn->period_to,
        );
        $validation = $this->xsdValidator->validate($xml, $vatReturn, $config, $quadratura);

        $export = SwissVatXmlExport::create([
            'tenant_id' => $tenantId,
            'swiss_vat_return_id' => $vatReturn->id,
            'accounting_method' => (string) $vatReturn->accounting_method,
            'period_label' => (string) $vatReturn->period_label,
            'schema_version' => 'eCH-0217-v2.0',
            'file_name' => $this->xmlBuilder->fileName($vatReturn),
            'sha256_checksum' => hash('sha256', $xml),
            'xsd_valid' => $validation->xsdErrors === [],
            'quadratura_passed' => $validation->quadraturaPassed,
            'validation_errors' => $validation->allErrors(),
            'xml_payload' => $xml,
            'exported_by' => $userId,
            'exported_at' => now(),
            'created_by' => $userId,
        ]);

        if (! $validation->valid) {
            throw SwissVatException::invalid('suissetax_validation_failed', [
                'count' => count($validation->allErrors()),
                'first' => $validation->allErrors()[0] ?? 'XSD error',
            ]);
        }

        return $export;
    }

    /**
     * @return Collection<int, SwissVatXmlExport>
     */
    public function listExports(string $tenantId, string $returnId): Collection
    {
        $this->find($tenantId, $returnId);

        return SwissVatXmlExport::query()
            ->where('tenant_id', $tenantId)
            ->where('swiss_vat_return_id', $returnId)
            ->orderByDesc('exported_at')
            ->get();
    }

    public function findExport(string $tenantId, string $exportId): SwissVatXmlExport
    {
        $export = SwissVatXmlExport::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($exportId)
            ->first();

        if ($export === null) {
            throw SwissVatException::notFound('xml_export_not_found');
        }

        return $export;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function resolvePeriodRange(int $year, string $periodType, int $periodNumber): array
    {
        $freq = SwissVatFrequency::from($periodType);

        return match ($freq) {
            SwissVatFrequency::Quarterly => (static function () use ($year, $periodNumber): array {
                if ($periodNumber < 1 || $periodNumber > 4) {
                    throw SwissVatException::invalid('invalid_period_number', ['max' => 4]);
                }
                $from = CarbonImmutable::create($year, ($periodNumber - 1) * 3 + 1, 1)->startOfDay();

                return [$from, $from->addMonths(3)->subDay()->endOfDay(), sprintf('%d-Q%d', $year, $periodNumber)];
            })(),
            SwissVatFrequency::Semiannual => (static function () use ($year, $periodNumber): array {
                if ($periodNumber < 1 || $periodNumber > 2) {
                    throw SwissVatException::invalid('invalid_period_number', ['max' => 2]);
                }
                $from = CarbonImmutable::create($year, ($periodNumber - 1) * 6 + 1, 1)->startOfDay();

                return [$from, $from->addMonths(6)->subDay()->endOfDay(), sprintf('%d-S%d', $year, $periodNumber)];
            })(),
            SwissVatFrequency::Monthly => (static function () use ($year, $periodNumber): array {
                if ($periodNumber < 1 || $periodNumber > 12) {
                    throw SwissVatException::invalid('invalid_period_number', ['max' => 12]);
                }
                $from = CarbonImmutable::create($year, $periodNumber, 1)->startOfDay();

                return [$from, $from->endOfMonth()->endOfDay(), sprintf('%d-%02d', $year, $periodNumber)];
            })(),
        };
    }
}
