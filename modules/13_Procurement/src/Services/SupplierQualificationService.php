<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Enums\SupplierQualificationStatus;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierDocument;

/**
 * Supplier onboarding: qualification status and qualification documents with expiry. Which documents are mandatory is
 * decided by the company; a mandatory document past its expiry date makes the qualification expire, which blocks new
 * purchase orders until the document is renewed and the supplier qualified again.
 */
final readonly class SupplierQualificationService
{
    public function __construct(private ProcurementAuditLedgerService $auditLedger) {}

    /**
     * Sets the qualification status. Qualifying requires every mandatory document to be valid on the day.
     *
     * @throws ProcurementRuleViolation when a mandatory document is expired
     */
    public function setStatus(Supplier $supplier, SupplierQualificationStatus $status, ?string $reviewDate, ?string $notes): Supplier
    {
        return DB::transaction(function () use ($supplier, $status, $reviewDate, $notes): Supplier {
            $locked = Supplier::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            $before = (string) $locked->qualification_status;

            if ($status->canOrder() && $this->expiredMandatoryDocuments($locked)->isNotEmpty()) {
                throw new ProcurementRuleViolation('A mandatory qualification document is expired: renew it before qualifying the supplier.', 'qualification_status');
            }

            $locked->update([
                'qualification_status' => $status->value,
                'qualified_at' => $status->canOrder() ? now()->toDateString() : $locked->qualified_at,
                'qualification_review_date' => $reviewDate,
            ]);

            $this->auditLedger->record(action: 'SUPPLIER_QUALIFICATION_CHANGED', model: $locked, payloadBefore: ['qualification_status' => $before], payloadAfter: ['qualification_status' => $status->value, 'notes' => $notes]);

            return $locked;
        });
    }

    /**
     * Adds a qualification document to the supplier.
     *
     * @param  array{document_type: string, reference?: ?string, issued_at?: ?string, expires_at?: ?string, is_mandatory?: bool, attachment_id?: ?string, notes?: ?string}  $data
     */
    public function addDocument(Supplier $supplier, array $data, ?string $userId): SupplierDocument
    {
        return SupplierDocument::create([
            'tenant_id' => $supplier->tenant_id,
            'supplier_id' => $supplier->id,
            'document_type' => $data['document_type'],
            'reference' => $data['reference'] ?? null,
            'issued_at' => $data['issued_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'is_mandatory' => (bool) ($data['is_mandatory'] ?? false),
            'attachment_id' => $data['attachment_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $userId,
        ]);
    }

    /**
     * Mandatory documents of the supplier past their expiry date on the day (default today).
     *
     * @return Collection<int, SupplierDocument>
     */
    public function expiredMandatoryDocuments(Supplier $supplier, ?string $onDate = null): Collection
    {
        return SupplierDocument::query()
            ->where('supplier_id', $supplier->id)
            ->where('is_mandatory', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $onDate ?? now()->toDateString())
            ->get();
    }

    /**
     * Documents of the tenant expiring within the given days (already expired ones included), soonest first.
     *
     * @return Collection<int, SupplierDocument>
     */
    public function expiringDocuments(int $withinDays): Collection
    {
        return SupplierDocument::query()
            ->with('supplier')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($withinDays)->toDateString())
            ->orderBy('expires_at')
            ->get();
    }

    /**
     * Marks expired the qualification of the suppliers of the tenant with an expired mandatory document; returns how
     * many suppliers changed.
     */
    public function expireQualifications(): int
    {
        $suppliers = Supplier::query()
            ->whereIn('qualification_status', [SupplierQualificationStatus::Qualified->value, SupplierQualificationStatus::Conditional->value])
            ->whereHas('documents', static fn ($query) => $query->where('is_mandatory', true)->whereNotNull('expires_at')->where('expires_at', '<', now()->toDateString()))
            ->get();

        foreach ($suppliers as $supplier) {
            $supplier->update(['qualification_status' => SupplierQualificationStatus::Expired->value]);
            $this->auditLedger->record(action: 'SUPPLIER_QUALIFICATION_EXPIRED', model: $supplier, payloadBefore: null, payloadAfter: ['qualification_status' => 'expired']);
        }

        return $suppliers->count();
    }
}
