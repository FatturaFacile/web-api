<?php

declare(strict_types=1);

namespace Modules\Sustainability\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Procurement\Models\Supplier;
use Modules\SupplierPortal\Models\DocumentRequirement;
use Modules\SupplierPortal\Models\SupplierDocumentSubmission;
use Modules\Sustainability\Models\EsgReportingPeriod;
use Modules\Sustainability\Models\EsgSupplierDataRequest;
use Modules\Sustainability\Support\ActivityCatalog;
use Modules\Sustainability\Support\Decimal;

/**
 * Supplier-specific emission data for Scope 3. The request rides on the supplier portal contract: a document
 * requirement (code ESG_SUPPLIER_EMISSIONS) that the supplier fulfils with its emission statement, and the submission
 * is linked to the request. Applying a received request turns the reported emissions into a dedicated emission factor
 * (kg CO2e per unit of the purchased quantity) and an activity record of the period, so the figure flows into the
 * inventory with the frozen factor like any other record.
 */
final readonly class SupplierDataRequestService
{
    public function __construct(
        private EmissionFactorService $factors,
        private ActivityDataService $activities,
        private SustainabilityLedger $ledger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data, ?string $actorId): EsgSupplierDataRequest
    {
        $period = EsgReportingPeriod::query()->where('tenant_id', $tenantId)->findOrFail($data['reporting_period_id']);
        $supplier = Supplier::query()->where('tenant_id', $tenantId)->find($data['supplier_id']);
        if ($supplier === null) {
            throw ValidationException::withMessages(['supplier_id' => [__('sustainability_errors.supplier_request.supplier_unknown')]]);
        }

        $this->assertActivity((int) $data['scope3_category'], (string) $data['activity_type'], (string) $data['unit']);

        return DB::transaction(function () use ($tenantId, $data, $actorId, $period, $supplier): EsgSupplierDataRequest {
            $request = EsgSupplierDataRequest::create([
                'tenant_id' => $tenantId,
                'reporting_period_id' => $period->id,
                'supplier_id' => $supplier->id,
                'document_requirement_id' => $this->requirement($tenantId)->id,
                'scope3_category' => (int) $data['scope3_category'],
                'activity_type' => $data['activity_type'],
                'unit' => $data['unit'],
                'quantity' => Decimal::round((string) $data['quantity'], 4),
                'status' => EsgSupplierDataRequest::Requested,
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'requested_by' => $actorId,
            ]);

            $this->ledger->record('ESG_SUPPLIER_REQUEST_SENT', $request, ['supplier_id' => $supplier->id, 'category' => $request->scope3_category]);

            return $request;
        });
    }

    /**
     * Registers the data the supplier sent: the reported emissions of the quantity requested and, when the supplier
     * uploaded its statement through the portal, the submission it travels with.
     *
     * @param  array<string, mixed>  $data
     */
    public function receive(EsgSupplierDataRequest $request, array $data): EsgSupplierDataRequest
    {
        $this->assertStatus($request, [EsgSupplierDataRequest::Requested, EsgSupplierDataRequest::Received]);

        $submissionId = $data['submission_id'] ?? null;
        if ($submissionId !== null) {
            $submission = SupplierDocumentSubmission::query()
                ->where('tenant_id', $request->tenant_id)
                ->where('supplier_id', $request->supplier_id)
                ->where('requirement_id', $request->document_requirement_id)
                ->whereIn('status', [SupplierDocumentSubmission::Submitted, SupplierDocumentSubmission::Accepted])
                ->find($submissionId);

            if ($submission === null) {
                throw ValidationException::withMessages(['submission_id' => [__('sustainability_errors.supplier_request.submission_invalid')]]);
            }
        }

        $request->update([
            'status' => EsgSupplierDataRequest::Received,
            'reported_co2e_kg' => Decimal::round((string) $data['reported_co2e_kg'], 4),
            'calculation_method' => $data['calculation_method'] ?? null,
            'submission_id' => $submissionId ?? $request->submission_id,
            'received_at' => now(),
        ]);

        $this->ledger->record('ESG_SUPPLIER_REQUEST_RECEIVED', $request, ['supplier_id' => $request->supplier_id]);

        return $request->refresh();
    }

    public function apply(EsgSupplierDataRequest $request, ?string $actorId): EsgSupplierDataRequest
    {
        $this->assertStatus($request, [EsgSupplierDataRequest::Received]);

        return DB::transaction(function () use ($request, $actorId): EsgSupplierDataRequest {
            $period = EsgReportingPeriod::query()->findOrFail($request->reporting_period_id);
            $supplier = Supplier::query()->findOrFail($request->supplier_id);
            $quantity = (string) $request->quantity;

            $factor = $this->factors->create((string) $request->tenant_id, [
                'code' => sprintf('SUP-%s-%s-%d', strtoupper(substr(str_replace('-', '', (string) $supplier->id), 0, 8)), $request->activity_type, $period->ends_on->year),
                'name' => "Supplier-specific: {$supplier->company_name} ({$request->activity_type})",
                'activity_type' => $request->activity_type,
                'unit' => $request->unit,
                'kg_co2e_per_unit' => Decimal::div((string) $request->reported_co2e_kg, $quantity),
                'region' => 'GLOBAL',
                'source' => 'Supplier-specific data of '.$supplier->company_name.($request->calculation_method !== null ? ": {$request->calculation_method}" : ''),
                'source_year' => $period->ends_on->year,
            ]);

            $record = $this->activities->create((string) $request->tenant_id, [
                'emission_factor_id' => $factor->id,
                'activity_type' => $request->activity_type,
                'activity_date' => $period->ends_on->toDateString(),
                'quantity' => $quantity,
                'unit' => $request->unit,
                'data_quality' => 'measured',
                'reference' => $supplier->company_name,
                'source_type' => 'esg_supplier_data_request',
                'source_id' => $request->id,
            ], $actorId);

            $request->update(['status' => EsgSupplierDataRequest::Applied, 'emission_factor_id' => $factor->id, 'activity_record_id' => $record->id, 'applied_at' => now()]);
            $this->ledger->record('ESG_SUPPLIER_REQUEST_APPLIED', $request, ['activity_record_id' => $record->id]);

            return $request->refresh();
        });
    }

    public function decline(EsgSupplierDataRequest $request): EsgSupplierDataRequest
    {
        return $this->close($request, EsgSupplierDataRequest::Declined, 'ESG_SUPPLIER_REQUEST_DECLINED');
    }

    public function cancel(EsgSupplierDataRequest $request): EsgSupplierDataRequest
    {
        return $this->close($request, EsgSupplierDataRequest::Cancelled, 'ESG_SUPPLIER_REQUEST_CANCELLED');
    }

    private function close(EsgSupplierDataRequest $request, string $status, string $event): EsgSupplierDataRequest
    {
        $this->assertStatus($request, [EsgSupplierDataRequest::Requested, EsgSupplierDataRequest::Received]);
        $request->update(['status' => $status]);
        $this->ledger->record($event, $request);

        return $request->refresh();
    }

    /**
     * The portal document requirement the requests use, created on first need.
     */
    private function requirement(string $tenantId): DocumentRequirement
    {
        return DocumentRequirement::query()->where('tenant_id', $tenantId)->where('code', EsgSupplierDataRequest::RequirementCode)->first()
            ?? DocumentRequirement::create([
                'tenant_id' => $tenantId,
                'code' => EsgSupplierDataRequest::RequirementCode,
                'name' => 'Greenhouse gas emission statement (supplier-specific data for Scope 3)',
                'country' => null,
                'is_mandatory' => false,
                'requires_expiry' => false,
                'validity_days' => null,
                'alert_days' => 30,
                'is_active' => true,
                'sort_order' => 900,
            ]);
    }

    private function assertActivity(int $category, string $activityType, string $unit): void
    {
        if (! isset(ActivityCatalog::Types[$activityType]) || ActivityCatalog::scopeOf($activityType) !== 3) {
            throw ValidationException::withMessages(['activity_type' => [__('sustainability_errors.supplier_request.not_scope3', ['type' => $activityType])]]);
        }

        if (ActivityCatalog::scope3NumberOf(ActivityCatalog::categoryOf($activityType)) !== $category) {
            throw ValidationException::withMessages(['scope3_category' => [__('sustainability_errors.supplier_request.category_mismatch', ['type' => $activityType, 'category' => $category])]]);
        }

        if (! ActivityCatalog::allows($activityType, $unit)) {
            throw ValidationException::withMessages(['unit' => [__('sustainability_errors.factor.unit_not_allowed', ['unit' => $unit, 'type' => $activityType])]]);
        }
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertStatus(EsgSupplierDataRequest $request, array $allowed): void
    {
        if (! in_array($request->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => [__('sustainability_errors.supplier_request.status_expected', ['status' => $request->status, 'expected' => implode(', ', $allowed)])]]);
        }
    }
}
