<?php

declare(strict_types=1);

namespace Modules\Documents\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Documents\Enums\RetentionAction;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsDocumentVersion;
use Modules\DocumentTrust\Services\PreservationLock;

/**
 * Runs the retention policies. A legal hold always wins. Files that belong to an attachment are referenced, not owned,
 * so they are left in place.
 */
final readonly class DocumentRetention
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private AuditLedgerInterface $auditLedger) {}

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

        if (RetentionAction::from((string) $policy->action) === RetentionAction::Archive) {
            $document->update(['status' => 'archived']);
            $this->auditLedger->record('document_retention_archived', $document, null, ['retention_until' => $document->retention_until?->toDateString()], 'MODULE_25_DOCUMENTS');

            return;
        }

        $this->purge($document);
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
