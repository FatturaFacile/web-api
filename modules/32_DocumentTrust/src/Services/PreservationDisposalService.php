<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Documents\Models\DmsDocument;
use Modules\DocumentTrust\Enums\PreservationDocumentClass;
use Modules\DocumentTrust\Exceptions\DocumentTrustException;
use Modules\DocumentTrust\Models\PreservationDisposal;
use Modules\DocumentTrust\Models\PreservationDisposalProposal;
use Modules\DocumentTrust\Models\PreservationItem;
use Modules\DocumentTrust\Models\PreservationPackage;
use Modules\DocumentTrust\Models\PreservationPackageHold;

/**
 * Controlled disposal (scarto) of closed packages whose retention expired.
 *
 * Workflow: candidates (with the computed expiry and what blocks them) -> proposal -> approval by an owner or
 * administrator who is NOT the proposer (a direct two-person rule: the Workflow module has no definition for this) ->
 * execution, which deletes the files from the disk and writes an irrevocable disposal record with the manifest and its
 * SHA-256. The package row, its item rows (hashes and metadata) and the audit trail stay; the closed package and its
 * triggers are not modified, the disposal is a separate append-only record.
 *
 * Blocked while: the retention (the longer of the one fixed at closing and the tenant's current setting counted from
 * the closing date) has not expired; the accountant has not confirmed the retention periods; a legal hold is active on
 * the package or on a library document in it; a proposal is already open. Checked again at execution. A package whose
 * manifest no longer matches its hash is never disposed of (it needs investigation first).
 *
 * This is a technical control, not legal advice: whether a document may be destroyed is for the accountant to confirm.
 */
final readonly class PreservationDisposalService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private PreservationRetention $retention, private PreservationAccessLogger $logger) {}

    /**
     * The expiry of a package and what blocks its disposal.
     *
     * @return array{expiry_date: Carbon, expired: bool, eligible: bool, blockers: list<string>}
     */
    public function assess(PreservationPackage $package): array
    {
        $current = $this->retention->effective(PreservationDocumentClass::from($package->document_class))['years'];
        $closedAt = $package->closed_at ?? Carbon::now();
        $expiry = Carbon::parse(max(
            $package->retention_until?->toDateString() ?? $closedAt->toDateString(),
            $closedAt->copy()->addYears($current)->toDateString(),
        ));
        $blockers = [];

        if (PreservationDisposal::withoutGlobalScopes()->where('package_id', $package->id)->exists()) {
            $blockers[] = 'already_disposed';
        }

        $expired = $expiry->lt(Carbon::today());

        if (! $expired) {
            $blockers[] = 'retention_not_expired';
        }

        if (! $this->retention->confirmed()) {
            $blockers[] = 'retention_not_confirmed';
        }

        if ($this->onHold($package)) {
            $blockers[] = 'legal_hold';
        }

        if (PreservationDisposalProposal::withoutGlobalScopes()->where('package_id', $package->id)->whereIn('status', [PreservationDisposalProposal::Pending, PreservationDisposalProposal::Approved])->exists()) {
            $blockers[] = 'proposal_open';
        }

        return ['expiry_date' => $expiry, 'expired' => $expired, 'eligible' => $blockers === [], 'blockers' => $blockers];
    }

    /**
     * Closed packages not yet disposed of, with their assessment.
     *
     * @return Collection<int, array{package: PreservationPackage, assessment: array{expiry_date: Carbon, expired: bool, eligible: bool, blockers: list<string>}}>
     */
    public function candidates(bool $onlyEligible = false): Collection
    {
        $rows = [];

        foreach (PreservationPackage::where('status', PreservationPackage::Closed)->whereNotIn('id', PreservationDisposal::withoutGlobalScopes()->select('package_id'))->orderBy('closed_at')->get() as $package) {
            $assessment = $this->assess($package);

            if (! $onlyEligible || $assessment['eligible']) {
                $rows[] = ['package' => $package, 'assessment' => $assessment];
            }
        }

        return collect($rows);
    }

    /**
     * Put a legal hold on a closed package.
     */
    public function placeHold(PreservationPackage $package, string $reason, User $user): PreservationPackageHold
    {
        if (! $package->isClosed()) {
            throw new DocumentTrustException('Only a closed package can be put on legal hold.');
        }

        $hold = PreservationPackageHold::create(['tenant_id' => $package->tenant_id, 'package_id' => $package->id, 'reason' => $reason, 'placed_by' => $user->id, 'placed_at' => now()]);
        $this->logger->record('hold_place', $package, ['hold_id' => $hold->id, 'reason' => $reason]);

        return $hold;
    }

    /**
     * Release the active legal holds of a package.
     */
    public function releaseHold(PreservationPackage $package, User $user): int
    {
        $released = PreservationPackageHold::where('package_id', $package->id)->whereNull('released_at')->update(['released_by' => $user->id, 'released_at' => now()]);
        $this->logger->record('hold_release', $package, ['released' => $released]);

        return $released;
    }

    /**
     * Propose the disposal of an eligible package.
     *
     * @throws DocumentTrustException when the package is not eligible
     */
    public function propose(PreservationPackage $package, ?string $note, User $user): PreservationDisposalProposal
    {
        $assessment = $this->assess($package);

        if (! $assessment['eligible']) {
            throw new DocumentTrustException('The package cannot be proposed for disposal: '.implode(', ', $assessment['blockers']).'.');
        }

        $proposal = PreservationDisposalProposal::create([
            'tenant_id' => $package->tenant_id, 'package_id' => $package->id, 'status' => PreservationDisposalProposal::Pending,
            'expiry_date' => $assessment['expiry_date']->toDateString(), 'note' => $note, 'proposed_by' => $user->id, 'proposed_at' => now(),
        ]);
        $this->logger->record('disposal_propose', $package, ['proposal_id' => $proposal->id, 'expiry_date' => $assessment['expiry_date']->toDateString()]);

        return $proposal;
    }

    /**
     * Approve a pending proposal. The approver must differ from the proposer.
     *
     * @throws DocumentTrustException
     */
    public function approve(PreservationDisposalProposal $proposal, ?string $note, User $user): PreservationDisposalProposal
    {
        return $this->decide($proposal, PreservationDisposalProposal::Approved, 'disposal_approve', $note, $user);
    }

    /**
     * Reject a pending proposal. The decider must differ from the proposer.
     *
     * @throws DocumentTrustException
     */
    public function reject(PreservationDisposalProposal $proposal, ?string $note, User $user): PreservationDisposalProposal
    {
        return $this->decide($proposal, PreservationDisposalProposal::Rejected, 'disposal_reject', $note, $user);
    }

    /**
     * Execute an approved proposal: delete the files, keep the manifest, write the irrevocable record.
     *
     * @throws DocumentTrustException when something blocks the disposal now, or the manifest was tampered with
     */
    public function execute(PreservationDisposalProposal $proposal, User $user): PreservationDisposal
    {
        $package = PreservationPackage::findOrFail($proposal->package_id);

        if ($proposal->status !== PreservationDisposalProposal::Approved || $proposal->decided_by === null) {
            throw new DocumentTrustException('Only an approved proposal can be executed.');
        }

        $blockers = array_values(array_diff($this->assess($package)['blockers'], ['proposal_open']));

        if ($blockers !== []) {
            throw new DocumentTrustException('The package cannot be disposed of now: '.implode(', ', $blockers).'.');
        }

        $manifest = (string) $package->manifest_json;

        if (! hash_equals((string) $package->package_hash, hash('sha256', $manifest))) {
            throw new DocumentTrustException('The manifest of the package no longer matches its hash: investigate before disposing of it.');
        }

        $items = PreservationItem::where('package_id', $package->id)->orderBy('sequence')->get();

        foreach ($items as $item) {
            Storage::disk($item->storage_disk)->delete($item->storage_path);
        }

        $disposal = DB::transaction(function () use ($proposal, $package, $manifest, $items, $user): PreservationDisposal {
            $locked = PreservationDisposalProposal::lockForUpdate()->findOrFail($proposal->id);

            if ($locked->status !== PreservationDisposalProposal::Approved) {
                throw new DocumentTrustException('The proposal was already executed.');
            }

            $disposal = PreservationDisposal::create([
                'tenant_id' => $package->tenant_id,
                'package_id' => $package->id,
                'proposal_id' => $locked->id,
                'document_class' => $package->document_class,
                'package_name' => $package->name,
                'items_count' => $package->items_count,
                'total_bytes' => $package->total_bytes,
                'retention_until' => $package->retention_until?->toDateString(),
                'expiry_date' => $locked->expiry_date?->toDateString(),
                'manifest_sha256' => hash('sha256', $manifest),
                'manifest_json' => $manifest,
                'files' => $items->map(fn (PreservationItem $item): array => ['item_id' => $item->id, 'file_name' => $item->file_name, 'sha256' => $item->sha256, 'size' => $item->size, 'document_number' => $item->document_number])->all(),
                'proposed_by' => $locked->proposed_by,
                'approved_by' => $locked->decided_by,
                'executed_by' => $user->id,
                'executed_at' => now(),
            ]);

            $locked->status = PreservationDisposalProposal::Executed;
            $locked->executed_by = $user->id;
            $locked->executed_at = now();
            $locked->save();

            return $disposal;
        });

        $this->logger->record('disposal_execute', $package, ['disposal_id' => $disposal->id, 'manifest_sha256' => $disposal->manifest_sha256, 'files' => $items->count(), 'proposed_by' => $disposal->proposed_by, 'approved_by' => $disposal->approved_by]);

        return $disposal;
    }

    private function decide(PreservationDisposalProposal $proposal, string $status, string $action, ?string $note, User $user): PreservationDisposalProposal
    {
        if ($proposal->status !== PreservationDisposalProposal::Pending) {
            throw new DocumentTrustException('The proposal has already been decided.');
        }

        if ((string) $proposal->proposed_by === (string) $user->id) {
            throw new DocumentTrustException('The proposer cannot decide their own proposal: another owner or administrator must.');
        }

        $proposal->status = $status;
        $proposal->decided_by = $user->id;
        $proposal->decided_at = now();
        $proposal->decision_note = $note;
        $proposal->save();
        $this->logger->record($action, PreservationPackage::findOrFail($proposal->package_id), ['proposal_id' => $proposal->id, 'note' => $note]);

        return $proposal;
    }

    private function onHold(PreservationPackage $package): bool
    {
        if (PreservationPackageHold::withoutGlobalScopes()->where('package_id', $package->id)->whereNull('released_at')->exists()) {
            return true;
        }

        $documentIds = PreservationItem::withoutGlobalScopes()->where('package_id', $package->id)->where('source_type', 'dms_document')->pluck('source_id');

        return $documentIds->isNotEmpty() && DmsDocument::withoutGlobalScopes()->whereIn('id', $documentIds)->where('legal_hold', true)->exists();
    }
}
