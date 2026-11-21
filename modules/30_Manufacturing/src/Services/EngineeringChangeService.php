<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Carbon\CarbonImmutable;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\Events\BomEcoSubmittedEvent;
use Modules\Manufacturing\Events\EngineeringChangeImplementedEvent;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgEngineeringChangeOrder;

/**
 * Engineering change orders on BOMs: draft, submitted for approval, approved or rejected by someone other than the
 * requester, implemented as a new BOM revision effective from the change's effective date (the previous revision ends
 * the day before, so production orders released before keep their components).
 */
final readonly class EngineeringChangeService
{
    public function __construct(
        private BillOfMaterialsServiceInterface $boms,
        private EventDispatcher $dispatcher,
    ) {}

    /**
     * Creates a draft change on a BOM revision.
     *
     * @param  array{bom_id: string, title: string, reason: string, description?: string|null, change_type?: string|null, effective_date?: string|null, new_revision?: string|null, components: list<array<string, mixed>>}  $data
     */
    public function create(string $tenantId, array $data, ?string $actorUserId): MfgEngineeringChangeOrder
    {
        $bom = MfgBom::query()->where('tenant_id', $tenantId)->findOrFail($data['bom_id']);
        if ($bom->isDraft()) {
            throw new ManufacturingOperationException('A draft BOM is changed directly: engineering changes apply to released revisions.', 'bom_id');
        }

        return MfgEngineeringChangeOrder::create([
            'tenant_id' => $tenantId,
            'eco_number' => 'ECO-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
            'title' => $data['title'],
            'reason' => $data['reason'],
            'description' => $data['description'] ?? null,
            'change_type' => $data['change_type'] ?? 'bom_change',
            'status' => 'draft',
            'bom_id' => $bom->id,
            'effective_date' => $data['effective_date'] ?? now()->toDateString(),
            'requested_by' => $this->uuidOrNull($actorUserId),
            'change_payload' => ['new_revision' => $data['new_revision'] ?? null, 'components' => $data['components']],
        ]);
    }

    /**
     * Opens and submits the change of a revision that was already prepared as a draft: the change stores the structural
     * diff between the released revision and the proposal, the proposal waits for the approval (Mod 006 workflow) and
     * is released by the implementation.
     *
     * @param  array<string, mixed>  $diff
     * @param  list<array<string, mixed>>  $componentChanges
     */
    public function openForRevision(MfgBom $source, MfgBom $proposal, array $diff, string $title, string $reason, ?string $effectiveDate, array $componentChanges, ?string $actorUserId): MfgEngineeringChangeOrder
    {
        return DB::transaction(function () use ($source, $proposal, $diff, $title, $reason, $effectiveDate, $componentChanges, $actorUserId): MfgEngineeringChangeOrder {
            $eco = MfgEngineeringChangeOrder::create([
                'tenant_id' => $source->tenant_id,
                'eco_number' => 'ECO-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
                'title' => $title,
                'reason' => $reason,
                'change_type' => 'bom_change',
                'status' => 'draft',
                'bom_id' => $source->id,
                'resulting_bom_id' => $proposal->id,
                'effective_date' => $effectiveDate ?? now()->toDateString(),
                'requested_by' => $this->uuidOrNull($actorUserId),
                'change_payload' => ['new_revision' => $proposal->revision, 'components' => $componentChanges, 'proposal_bom_id' => $proposal->id],
                'diff_payload' => $diff,
            ]);

            $proposal->update(['engineering_change_order_id' => $eco->id, 'status' => 'pending_eco_approval']);
            $submitted = $this->submit($eco);

            $instance = DB::table('workflow_instances')
                ->where('approvable_type', MfgEngineeringChangeOrder::class)
                ->where('approvable_id', $eco->id)
                ->orderByDesc('created_at')
                ->first(['id']);
            if ($instance !== null) {
                $submitted->update(['approval_workflow_id' => (string) $instance->id]);
            }

            /** @var array{added: int, removed: int, modified: int, unchanged: int} $summary */
            $summary = $diff['summary'] ?? ['added' => 0, 'removed' => 0, 'modified' => 0, 'unchanged' => 0];
            $this->dispatcher->publishAndDispatch(new BomEcoSubmittedEvent(
                engineeringChangeOrderId: (string) $eco->id,
                ecoNumber: $eco->eco_number,
                sourceBomId: (string) $source->id,
                proposedBomId: (string) $proposal->id,
                diffSummary: $summary,
                tenantId: (string) $source->tenant_id
            ));

            return $submitted->refresh();
        });
    }

    /**
     * Submits a draft change for approval.
     */
    public function submit(MfgEngineeringChangeOrder $eco): MfgEngineeringChangeOrder
    {
        return $this->transition($eco, 'draft', function (MfgEngineeringChangeOrder $locked): void {
            $locked->update(['status' => 'submitted', 'submitted_at' => now()]);
        });
    }

    /**
     * Approves a submitted change; the requester cannot approve their own change.
     */
    public function approve(MfgEngineeringChangeOrder $eco, string $approverId): MfgEngineeringChangeOrder
    {
        return $this->transition($eco, 'submitted', function (MfgEngineeringChangeOrder $locked) use ($approverId): void {
            if ($locked->requested_by !== null && (string) $locked->requested_by === $approverId) {
                throw new ManufacturingOperationException('The requester of an engineering change cannot approve it.', 'approved_by');
            }

            $locked->update(['status' => 'approved', 'approved_by' => $this->uuidOrNull($approverId), 'approved_at' => now()]);
        });
    }

    /**
     * Rejects a submitted change with the reason.
     */
    public function reject(MfgEngineeringChangeOrder $eco, string $rejecterId, string $reason): MfgEngineeringChangeOrder
    {
        return $this->transition($eco, 'submitted', function (MfgEngineeringChangeOrder $locked) use ($rejecterId, $reason): void {
            $locked->update(['status' => 'rejected', 'rejected_by' => $this->uuidOrNull($rejecterId), 'rejected_at' => now(), 'rejection_reason' => $reason]);

            // The proposed revision goes back to engineering as a draft.
            MfgBom::query()->where('tenant_id', $locked->tenant_id)->whereKey($locked->resulting_bom_id)->where('status', 'pending_eco_approval')->update(['status' => 'draft']);
        });
    }

    /**
     * Implements an approved change: creates the new BOM revision with the component changes and releases it from the
     * effective date.
     */
    public function implement(MfgEngineeringChangeOrder $eco, ?string $actorUserId): MfgEngineeringChangeOrder
    {
        return DB::transaction(function () use ($eco, $actorUserId): MfgEngineeringChangeOrder {
            $locked = MfgEngineeringChangeOrder::query()->whereKey($eco->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isApproved()) {
                throw new ManufacturingOperationException("Engineering change [{$locked->eco_number}] must be approved before it is implemented (status: {$locked->status}).", 'status');
            }

            $bom = MfgBom::query()->where('tenant_id', $locked->tenant_id)->findOrFail($locked->bom_id);
            $payload = $locked->change_payload ?? [];
            $effective = CarbonImmutable::parse($locked->effective_date ?? now())->toDateString();
            $components = is_array($payload['components'] ?? null) ? array_values($payload['components']) : [];
            $revision = isset($payload['new_revision']) && is_string($payload['new_revision']) && $payload['new_revision'] !== '' ? $payload['new_revision'] : null;

            $proposal = $locked->resulting_bom_id !== null ? MfgBom::query()->where('tenant_id', $locked->tenant_id)->find($locked->resulting_bom_id) : null;
            if ($proposal instanceof MfgBom && $proposal->isPendingApproval()) {
                // The revision was prepared when the change was opened: it is released from the effective date.
                $proposal->update(['status' => 'draft', 'effective_from' => $effective]);
                $draft = $proposal;
            } else {
                $draft = $this->boms->reviseBom($bom, $revision, "Engineering change {$locked->eco_number}: {$locked->title}", $components, $effective, $actorUserId);
            }
            $draft->update(['engineering_change_order_id' => $locked->id]);
            $released = $this->boms->activate($draft, $locked->approved_by ?? $actorUserId);

            $locked->update(['status' => 'implemented', 'implemented_at' => now(), 'resulting_bom_id' => $released->id]);

            $this->dispatcher->publishAndDispatch(new EngineeringChangeImplementedEvent(
                engineeringChangeOrderId: (string) $locked->id,
                ecoNumber: $locked->eco_number,
                previousBomId: (string) $bom->id,
                resultingBomId: (string) $released->id,
                effectiveDate: $effective,
                tenantId: (string) $locked->tenant_id
            ));

            return $locked->refresh();
        });
    }

    /**
     * Runs a status change under a row lock when the change is in the expected status.
     *
     * @param  callable(MfgEngineeringChangeOrder): void  $change
     */
    private function transition(MfgEngineeringChangeOrder $eco, string $expected, callable $change): MfgEngineeringChangeOrder
    {
        return DB::transaction(function () use ($eco, $expected, $change): MfgEngineeringChangeOrder {
            $locked = MfgEngineeringChangeOrder::query()->whereKey($eco->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== $expected) {
                throw new ManufacturingOperationException("Engineering change [{$locked->eco_number}] is {$locked->status}, not {$expected}.", 'status');
            }

            $change($locked);

            return $locked->refresh();
        });
    }

    /**
     * The id when it is a UUID (user ids are), else null.
     */
    private function uuidOrNull(?string $id): ?string
    {
        return $id !== null && Str::isUuid($id) ? $id : null;
    }
}
