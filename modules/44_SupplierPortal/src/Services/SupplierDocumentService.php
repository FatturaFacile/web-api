<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Services;

use App\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierDocument;
use Modules\Procurement\Services\SupplierQualificationService;
use Modules\SupplierPortal\Models\DocumentRequirement;
use Modules\SupplierPortal\Models\SupplierDocumentSubmission;

/**
 * Required documents of the suppliers: the catalogue of requirements per country, the documents sent for them and their
 * review. An accepted document is mirrored in the qualification documents of the procurement module, so the
 * qualification expiry rules of that module keep working; a renewal supersedes the previous document of the same type.
 */
final readonly class SupplierDocumentService
{
    /**
     * Requirements created by the "seed defaults" action: Italy, Switzerland and country independent ones.
     *
     * @var list<array{code: string, name: string, country: ?string, is_mandatory: bool, requires_expiry: bool, validity_days: ?int, alert_days: int}>
     */
    public const array DefaultRequirements = [
        ['code' => 'DURC', 'name' => 'DURC (certificate of regular social security contributions)', 'country' => 'IT', 'is_mandatory' => true, 'requires_expiry' => true, 'validity_days' => 120, 'alert_days' => 30],
        ['code' => 'VISURA_CAMERALE', 'name' => 'Visura camerale (chamber of commerce extract)', 'country' => 'IT', 'is_mandatory' => true, 'requires_expiry' => true, 'validity_days' => 180, 'alert_days' => 30],
        ['code' => 'ANTIMAFIA', 'name' => 'Certificato antimafia (anti-mafia certificate)', 'country' => 'IT', 'is_mandatory' => false, 'requires_expiry' => true, 'validity_days' => 365, 'alert_days' => 45],
        ['code' => 'UID_EXTRACT', 'name' => 'Swiss UID register extract', 'country' => 'CH', 'is_mandatory' => true, 'requires_expiry' => true, 'validity_days' => 180, 'alert_days' => 30],
        ['code' => 'HANDELSREGISTER_EXTRACT', 'name' => 'Handelsregister extract (Swiss commercial register)', 'country' => 'CH', 'is_mandatory' => true, 'requires_expiry' => true, 'validity_days' => 180, 'alert_days' => 30],
        ['code' => 'SOCIAL_INSURANCE_CH', 'name' => 'Swiss social insurance compliance (AHV/SUVA)', 'country' => 'CH', 'is_mandatory' => false, 'requires_expiry' => true, 'validity_days' => 365, 'alert_days' => 45],
        ['code' => 'INSURANCE_RC', 'name' => 'Liability insurance certificate', 'country' => null, 'is_mandatory' => true, 'requires_expiry' => true, 'validity_days' => null, 'alert_days' => 30],
        ['code' => 'ISO_9001', 'name' => 'ISO 9001 quality management certificate', 'country' => null, 'is_mandatory' => false, 'requires_expiry' => true, 'validity_days' => null, 'alert_days' => 60],
        ['code' => 'ISO_14001', 'name' => 'ISO 14001 environmental management certificate', 'country' => null, 'is_mandatory' => false, 'requires_expiry' => true, 'validity_days' => null, 'alert_days' => 60],
        ['code' => 'ISO_45001', 'name' => 'ISO 45001 occupational health and safety certificate', 'country' => null, 'is_mandatory' => false, 'requires_expiry' => true, 'validity_days' => null, 'alert_days' => 60],
    ];

    public function __construct(
        private SupplierQualificationService $qualification,
        private SupplierRiskService $risk,
        private SupplierPortalLedger $ledger,
    ) {}

    /**
     * Creates the default requirements the company does not have yet; returns how many were created.
     */
    public function seedDefaults(string $tenantId): int
    {
        $created = 0;

        foreach (self::DefaultRequirements as $index => $definition) {
            $exists = DocumentRequirement::query()->where('tenant_id', $tenantId)->where('code', $definition['code'])->exists();

            if (! $exists) {
                DocumentRequirement::create([...$definition, 'tenant_id' => $tenantId, 'is_active' => true, 'sort_order' => ($index + 1) * 10]);
                $created++;
            }
        }

        return $created;
    }

    /**
     * Active requirements that apply to the suppliers of a country.
     *
     * @return Collection<int, DocumentRequirement>
     */
    public function requirementsFor(string $country): Collection
    {
        return DocumentRequirement::query()
            ->where('is_active', true)
            ->where(static fn (Builder $query) => $query->whereNull('country')->orWhere('country', strtoupper($country)))
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();
    }

    /**
     * Sends a document for a requirement: the file (when given) is stored, an earlier document of the same type that is
     * still waiting for review is superseded.
     *
     * @param  array{reference?: ?string, issued_at?: ?string, expires_at?: ?string, notes?: ?string}  $data
     */
    public function submit(Supplier $supplier, DocumentRequirement $requirement, array $data, ?UploadedFile $file, string $via, ?string $userId, ?string $onboardingId, string $country): SupplierDocumentSubmission
    {
        if (! $requirement->is_active || ($requirement->country !== null && strtoupper((string) $requirement->country) !== strtoupper($country))) {
            throw new BusinessRuleException('This document type is not required from this supplier.');
        }

        $path = $file?->store("supplier-portal/{$supplier->tenant_id}/{$supplier->id}", (string) config('supplier_portal.documents_disk'));

        return DB::transaction(function () use ($supplier, $requirement, $data, $file, $path, $via, $userId, $onboardingId): SupplierDocumentSubmission {
            SupplierDocumentSubmission::query()
                ->where('supplier_id', $supplier->id)
                ->where('requirement_id', $requirement->id)
                ->where('status', SupplierDocumentSubmission::Submitted)
                ->update(['status' => SupplierDocumentSubmission::Superseded]);

            $submission = SupplierDocumentSubmission::create([
                'tenant_id' => $supplier->tenant_id,
                'supplier_id' => $supplier->id,
                'onboarding_id' => $onboardingId,
                'requirement_id' => $requirement->id,
                'status' => SupplierDocumentSubmission::Submitted,
                'reference' => $data['reference'] ?? null,
                'issued_at' => $data['issued_at'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'file_path' => $path === false ? null : $path,
                'original_name' => $file?->getClientOriginalName(),
                'mime_type' => $file?->getClientMimeType(),
                'file_size' => $file?->getSize(),
                'submitted_via' => $via,
                'submitted_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->ledger->record('SUPPLIER_DOCUMENT_SUBMITTED', $submission, ['supplier_id' => $supplier->id, 'requirement' => $requirement->code, 'via' => $via]);

            return $submission;
        });
    }

    /**
     * Accepts a document: it must be valid today, and carry an expiry date when the requirement asks for one (given, or
     * issue date plus the validity of the requirement).
     *
     * @param  array{reference?: ?string, issued_at?: ?string, expires_at?: ?string}  $data
     *
     * @throws BusinessRuleException
     */
    public function accept(SupplierDocumentSubmission $submission, array $data, ?string $userId): SupplierDocumentSubmission
    {
        return DB::transaction(function () use ($submission, $data, $userId): SupplierDocumentSubmission {
            $locked = SupplierDocumentSubmission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SupplierDocumentSubmission::Submitted) {
                throw new BusinessRuleException("A document in status {$locked->status} cannot be accepted.");
            }

            $requirement = DocumentRequirement::query()->findOrFail($locked->requirement_id);
            $issuedAt = $data['issued_at'] ?? $locked->issued_at?->toDateString();
            $expiresAt = $data['expires_at'] ?? $locked->expires_at?->toDateString();

            if ($expiresAt === null && $issuedAt !== null && $requirement->validity_days !== null) {
                $expiresAt = CarbonImmutable::parse($issuedAt)->addDays($requirement->validity_days)->toDateString();
            }

            if ($requirement->requires_expiry && $expiresAt === null) {
                throw new BusinessRuleException('This document type needs an expiry date.');
            }

            if ($expiresAt !== null && $expiresAt < now()->toDateString()) {
                throw new BusinessRuleException('An expired document cannot be accepted.');
            }

            $supplier = Supplier::query()->findOrFail($locked->supplier_id);
            $reference = $data['reference'] ?? $locked->reference;

            $this->supersedeAccepted($locked, $requirement);

            $mirror = $this->qualification->addDocument($supplier, [
                'document_type' => $requirement->code,
                'reference' => $reference,
                'issued_at' => $issuedAt,
                'expires_at' => $expiresAt,
                'is_mandatory' => $requirement->is_mandatory,
                'notes' => 'Supplier portal submission '.$locked->id,
            ], $userId);

            $locked->update([
                'status' => SupplierDocumentSubmission::Accepted,
                'reference' => $reference,
                'issued_at' => $issuedAt,
                'expires_at' => $expiresAt,
                'supplier_document_id' => $mirror->id,
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);

            $this->ledger->record('SUPPLIER_DOCUMENT_ACCEPTED', $locked, ['supplier_id' => $supplier->id, 'requirement' => $requirement->code, 'expires_at' => $expiresAt]);
            $this->risk->evaluate($supplier);

            return $locked;
        });
    }

    /**
     * @throws BusinessRuleException
     */
    public function reject(SupplierDocumentSubmission $submission, string $reason, ?string $userId): SupplierDocumentSubmission
    {
        return DB::transaction(function () use ($submission, $reason, $userId): SupplierDocumentSubmission {
            $locked = SupplierDocumentSubmission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SupplierDocumentSubmission::Submitted) {
                throw new BusinessRuleException("A document in status {$locked->status} cannot be rejected.");
            }

            $locked->update(['status' => SupplierDocumentSubmission::Rejected, 'rejection_reason' => $reason, 'reviewed_by' => $userId, 'reviewed_at' => now()]);
            $this->ledger->record('SUPPLIER_DOCUMENT_REJECTED', $locked, ['supplier_id' => $locked->supplier_id]);

            return $locked;
        });
    }

    /**
     * Mandatory requirements of the country with no accepted, unexpired document of the supplier.
     *
     * @return Collection<int, DocumentRequirement>
     */
    public function missingMandatory(string $supplierId, string $country): Collection
    {
        $valid = SupplierDocumentSubmission::query()
            ->where('supplier_id', $supplierId)
            ->where('status', SupplierDocumentSubmission::Accepted)
            ->where(static fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>=', now()->toDateString()))
            ->pluck('requirement_id')
            ->all();

        return $this->requirementsFor($country)->filter(static fn (DocumentRequirement $requirement): bool => $requirement->is_mandatory && ! in_array($requirement->id, $valid, true))->values();
    }

    /**
     * Mandatory requirements of the country the supplier has not sent anything for yet (or only got rejected).
     *
     * @return Collection<int, DocumentRequirement>
     */
    public function notYetSent(string $supplierId, string $country): Collection
    {
        $sent = SupplierDocumentSubmission::query()
            ->where('supplier_id', $supplierId)
            ->whereIn('status', [SupplierDocumentSubmission::Submitted, SupplierDocumentSubmission::Accepted])
            ->pluck('requirement_id')
            ->all();

        return $this->requirementsFor($country)->filter(static fn (DocumentRequirement $requirement): bool => $requirement->is_mandatory && ! in_array($requirement->id, $sent, true))->values();
    }

    /**
     * Accepted documents expiring within the given days, already expired ones included, soonest first.
     *
     * @return Collection<int, SupplierDocumentSubmission>
     */
    public function expiring(int $withinDays): Collection
    {
        return SupplierDocumentSubmission::query()
            ->with(['requirement', 'supplier'])
            ->where('status', SupplierDocumentSubmission::Accepted)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', now()->addDays($withinDays)->toDateString())
            ->orderBy('expires_at')
            ->get();
    }

    /**
     * The document accepted before is replaced by the renewal: it stays as history and no longer counts as a mandatory
     * qualification document of the procurement module.
     */
    private function supersedeAccepted(SupplierDocumentSubmission $renewal, DocumentRequirement $requirement): void
    {
        $previous = SupplierDocumentSubmission::query()
            ->where('supplier_id', $renewal->supplier_id)
            ->where('requirement_id', $requirement->id)
            ->where('status', SupplierDocumentSubmission::Accepted)
            ->whereKeyNot($renewal->id)
            ->get();

        foreach ($previous as $old) {
            $old->update(['status' => SupplierDocumentSubmission::Superseded]);

            if ($old->supplier_document_id !== null) {
                SupplierDocument::query()->whereKey($old->supplier_document_id)->update(['is_mandatory' => false]);
            }
        }
    }
}
