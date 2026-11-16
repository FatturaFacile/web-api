<?php

declare(strict_types=1);

namespace Modules\Documents\Services;

use App\Services\Privacy\StatutoryRetentionCatalogue;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Documents\Enums\RetentionAction;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsDocumentVersion;
use Modules\DocumentTrust\Services\PreservationLock;

/**
 * Runs the retention policies. A legal hold always wins, and so does the statutory period of a document whose type the
 * catalogue classifies (invoices, ledger books, payslips, contracts): its retention date moves to the end of the
 * statutory period and the move is logged, so the document comes due again only when the period is over. Files that
 * belong to an attachment are referenced, not owned, so they are left in place.
 */
final readonly class DocumentRetention
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private AuditLedgerInterface $auditLedger) {}

    /**
     * Message refusing a purge policy shorter than the statutory period of the document type, or null when the policy
     * archives, the type is not classified or the period is long enough.
     */
    public static function statutoryPurgeConflict(?string $documentType, ?string $action, int $retentionMonths): ?string
    {
        $retention = StatutoryRetentionCatalogue::retentionForDocumentType($documentType);
        $years = $retention === null ? null : StatutoryRetentionCatalogue::minimumYears($retention);

        if ($years === null || $action !== RetentionAction::Delete->value || $retentionMonths >= $years * 12) {
            return null;
        }

        return "Documents of type \"{$documentType}\" must be kept for {$years} years by law: a delete policy needs at least ".($years * 12).' months, or use an archive policy.';
    }

    /**
     * @return int number of documents processed
     */
    public function applyDuePolicies(string $tenantId): int
    {
        $due = DmsDocument::withoutGlobalScopes()
            ->with('retentionPolicy')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->where('legal_hold', false)
            ->whereNotNull('retention_policy_id')
            ->whereDate('retention_until', '<=', now()->toDateString())
            ->get();

        foreach ($due as $document) {
            $this->process($document);
        }

        return $due->count();
    }

    private function process(DmsDocument $document): void
    {
        $policy = $document->retentionPolicy;

        if ($policy === null || ! $policy->is_active || PreservationLock::documentIsPreserved((string) $document->id)) {
            return;
        }

        if ($this->holdUntilStatutoryEnd($document)) {
            return;
        }

        if (RetentionAction::from((string) $policy->action) === RetentionAction::Archive) {
            $document->update(['status' => 'archived']);
            $this->auditLedger->record('document_retention_archived', $document, null, ['retention_until' => $document->retention_until?->toDateString()], 'MODULE_25_DOCUMENTS');

            return;
        }

        $this->purge($document);
    }

    /**
     * Keep a document whose statutory period, counted from the end of the year of its creation, is still running. Its
     * retention date moves to the last day of that period; a document of a held class without a date stays held and is
     * logged on every run.
     *
     * @return bool true when the document is held
     */
    private function holdUntilStatutoryEnd(DmsDocument $document): bool
    {
        $retention = StatutoryRetentionCatalogue::retentionForDocumentType($document->document_type);

        if ($retention === null || ! StatutoryRetentionCatalogue::isUnderRetention($retention, $document->created_at)) {
            return false;
        }

        $previous = $document->retention_until?->toDateString();
        $heldUntil = StatutoryRetentionCatalogue::retentionEndsAt($retention, $document->created_at)?->toDateString();

        if ($heldUntil !== null) {
            $document->update(['retention_until' => $heldUntil]);
        }

        $this->auditLedger->record('document_retention_held_statutory', $document, null, ['retention_until' => $previous, 'held_until' => $heldUntil, 'document_type' => $document->document_type], 'MODULE_25_DOCUMENTS');

        return true;
    }

    private function purge(DmsDocument $document): void
    {
        $files = DB::transaction(function () use ($document): array {
            $locked = DmsDocument::withoutGlobalScopes()->lockForUpdate()->findOrFail($document->id);

            if ($locked->legal_hold) {
                return [];
            }

            $versions = DmsDocumentVersion::withoutGlobalScopes()->where('document_id', $locked->id)->whereNull('deleted_at')->get();
            $owned = $versions->whereNull('attachment_id')->map(static fn (DmsDocumentVersion $version): array => ['disk' => $version->disk, 'path' => $version->path])->all();

            $versions->each(static fn (DmsDocumentVersion $version): ?bool => $version->delete());
            $locked->delete();
            $this->auditLedger->record('document_retention_deleted', $locked, null, ['retention_until' => $locked->retention_until?->toDateString()], 'MODULE_25_DOCUMENTS');

            return $owned;
        });

        foreach ($files as $file) {
            Storage::disk($file['disk'])->delete($file['path']);
        }
    }
}
