<?php

declare(strict_types=1);

namespace Modules\Plm\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Plm\Enums\ApprovalDepartment;
use Modules\Plm\Enums\ChangeReason;
use Modules\Plm\Enums\EcoWorkflowStage;
use Modules\Plm\Enums\StockDispositionRule;
use Modules\Plm\Models\PlmAffectedBom;
use Modules\Plm\Models\PlmAffectedItem;
use Modules\Plm\Models\PlmChangeApproval;
use Modules\Plm\Models\PlmChangeOrder;
use Modules\Plm\Models\PlmItemRevision;

/**
 * Engineering change orders: draft, ECR submission, departmental approvals (Engineering, Quality,
 * Purchasing, Production), ECO approval, and ECN cutover implementation.
 */
final class ChangeOrderService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, ?string $userId, array $data): PlmChangeOrder
    {
        return DB::transaction(function () use ($tenantId, $userId, $data): PlmChangeOrder {
            $this->assertCodeFree($tenantId, (string) $data['code'], null);

            $changeReason = ChangeReason::tryFrom((string) ($data['change_reason'] ?? ChangeReason::CostReduction->value))
                ?? ChangeReason::CostReduction;
            $dispositionRule = StockDispositionRule::tryFrom((string) ($data['existing_stock_disposition_rule'] ?? StockDispositionRule::UseUpEsaurimentoScorte->value))
                ?? StockDispositionRule::UseUpEsaurimentoScorte;
            $isSafety = (bool) ($data['is_safety_immediate_cutover'] ?? $changeReason->requiresImmediateSafetyCutover());

            $matrix = $this->defaultApprovalsMatrix($data['approvals_matrix_json'] ?? null);

            $order = PlmChangeOrder::create([
                ...$this->fields($data),
                'tenant_id' => $tenantId,
                'status' => PlmChangeOrder::Draft,
                'workflow_stage' => EcoWorkflowStage::Draft->value,
                'change_reason' => $changeReason->value,
                'is_safety_immediate_cutover' => $isSafety,
                'existing_stock_disposition_rule' => $isSafety ? StockDispositionRule::ScrapImmediately->value : $dispositionRule->value,
                'approvals_matrix_json' => $matrix,
                'requested_by' => $userId,
            ]);
            $this->syncLinks($order, $data);

            return $this->loaded($order);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PlmChangeOrder $order, array $data): PlmChangeOrder
    {
        $this->assertDraft($order);

        return DB::transaction(function () use ($order, $data): PlmChangeOrder {
            if (isset($data['code'])) {
                $this->assertCodeFree((string) $order->tenant_id, (string) $data['code'], (string) $order->id);
            }

            $order->update($this->fields($data));
            $this->syncLinks($order, $data);

            return $this->loaded($order->refresh());
        });
    }

    public function delete(PlmChangeOrder $order): void
    {
        $this->assertDraft($order);

        DB::transaction(function () use ($order): void {
            $order->approvals()->delete();
            $order->affectedItems()->delete();
            $order->affectedBoms()->delete();
            $order->delete();
        });
    }

    public function submit(PlmChangeOrder $order): PlmChangeOrder
    {
        $this->assertDraft($order);

        if (! $order->approvals()->exists()) {
            throw ValidationException::withMessages(['approvers' => [__('plm_errors.order.approvers_required')]]);
        }

        if (! $order->affectedItems()->exists() && ! $order->affectedBoms()->exists()) {
            throw ValidationException::withMessages(['affected_items' => [__('plm_errors.order.affected_required')]]);
        }

        $order->update([
            'status' => PlmChangeOrder::InReview,
            'workflow_stage' => EcoWorkflowStage::EcrSubmitted->value,
            'submitted_at' => now(),
        ]);

        return $this->loaded($order->refresh());
    }

    /**
     * Records a departmental electronic sign-off (Engineering, Quality, Purchasing, Production).
     * When all 4 departments approve, the order transitions to EcoApproved.
     */
    public function recordDepartmentSignoff(
        PlmChangeOrder $order,
        string $userId,
        ApprovalDepartment $department,
        string $decision,
        ?string $comment = null,
        ?string $actorRole = null,
    ): PlmChangeOrder {
        return DB::transaction(function () use ($order, $userId, $department, $decision, $comment, $actorRole): PlmChangeOrder {
            $locked = PlmChangeOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [PlmChangeOrder::Draft, PlmChangeOrder::InReview], true)) {
                throw ValidationException::withMessages(['change_order' => [__('plm_errors.order.not_in_review')]]);
            }

            $approved = $decision === 'approve';
            if (! $approved && ($comment === null || trim($comment) === '')) {
                throw ValidationException::withMessages(['comment' => [__('plm_errors.approval.reject_comment_required')]]);
            }

            $timestamp = now();
            $hash = hash('sha256', implode('|', [$locked->id, $locked->code, $department->value, $userId, $decision, $timestamp->toIso8601String()]));

            $matrix = $this->defaultApprovalsMatrix($locked->approvals_matrix_json);
            $matrix[$department->value] = [
                'department' => $department->value,
                'status' => $approved ? 'approved' : 'rejected',
                'actor_id' => $userId,
                'actor_role' => $actorRole,
                'decided_at' => $timestamp->toIso8601String(),
                'comment' => $comment,
                'sign_off_hash' => $hash,
            ];

            PlmChangeApproval::updateOrCreate(
                [
                    'tenant_id' => $locked->tenant_id,
                    'change_order_id' => $locked->id,
                    'department' => $department->value,
                ],
                [
                    'approver_id' => $userId,
                    'sequence' => array_search($department, ApprovalDepartment::cases(), true) + 1,
                    'status' => $approved ? PlmChangeApproval::Approved : PlmChangeApproval::Rejected,
                    'actor_role' => $actorRole,
                    'sign_off_hash' => $hash,
                    'decided_at' => $timestamp,
                    'comment' => $comment,
                ]
            );

            $updates = [
                'approvals_matrix_json' => $matrix,
                'submitted_at' => $locked->submitted_at ?? $timestamp,
            ];

            if (! $approved) {
                $updates['status'] = PlmChangeOrder::Rejected;
                $updates['workflow_stage'] = EcoWorkflowStage::Rejected->value;
                $updates['decided_at'] = $timestamp;
                $updates['rejection_reason'] = $comment;
            } else {
                $locked->approvals_matrix_json = $matrix;
                if ($locked->hasAllDepartmentApprovals()) {
                    $updates['status'] = PlmChangeOrder::Approved;
                    $updates['workflow_stage'] = EcoWorkflowStage::EcoApproved->value;
                    $updates['decided_at'] = $timestamp;
                } else {
                    $updates['status'] = PlmChangeOrder::InReview;
                    $updates['workflow_stage'] = EcoWorkflowStage::UnderEvaluation->value;
                }
            }

            $locked->update($updates);

            return $this->loaded($locked->refresh());
        });
    }

    public function advanceStage(PlmChangeOrder $order, EcoWorkflowStage $targetStage): PlmChangeOrder
    {
        return DB::transaction(function () use ($order, $targetStage): PlmChangeOrder {
            $locked = PlmChangeOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $current = $locked->workflowStageEnum();

            if (! $current->canTransitionTo($targetStage)) {
                throw ValidationException::withMessages(['workflow_stage' => [__('plm_errors.order.invalid_stage_transition')]]);
            }

            if ($targetStage === EcoWorkflowStage::EcoApproved && is_array($locked->approvals_matrix_json) && ! $locked->hasAllDepartmentApprovals()) {
                throw ValidationException::withMessages(['approvals_matrix' => [__('plm_errors.order.department_approvals_required')]]);
            }

            $locked->update([
                'workflow_stage' => $targetStage->value,
                'status' => $targetStage->legacyStatus(),
                'submitted_at' => $targetStage === EcoWorkflowStage::EcrSubmitted && $locked->submitted_at === null ? now() : $locked->submitted_at,
            ]);

            return $this->loaded($locked->refresh());
        });
    }

    /**
     * The next pending approver decides: approving moves the flow on (the last approval approves the order), rejecting
     * rejects the order at once.
     */
    public function decide(PlmChangeOrder $order, string $userId, string $decision, ?string $comment): PlmChangeOrder
    {
        return DB::transaction(function () use ($order, $userId, $decision, $comment): PlmChangeOrder {
            $locked = PlmChangeOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PlmChangeOrder::InReview) {
                throw ValidationException::withMessages(['change_order' => [__('plm_errors.order.not_in_review')]]);
            }

            $step = PlmChangeApproval::query()->where('change_order_id', $locked->id)->where('status', PlmChangeApproval::Pending)->orderBy('sequence')->first();

            if ($step === null || (string) $step->approver_id !== $userId) {
                throw ValidationException::withMessages(['change_order' => [__('plm_errors.approval.not_your_turn')]]);
            }

            $approved = $decision === 'approve';

            if (! $approved && ($comment === null || trim($comment) === '')) {
                throw ValidationException::withMessages(['comment' => [__('plm_errors.approval.reject_comment_required')]]);
            }

            $timestamp = now();
            $hash = hash('sha256', implode('|', [$locked->id, $locked->code, (string) $step->id, $userId, $decision, $timestamp->toIso8601String()]));

            $step->update([
                'status' => $approved ? PlmChangeApproval::Approved : PlmChangeApproval::Rejected,
                'decided_at' => $timestamp,
                'comment' => $comment,
                'sign_off_hash' => $hash,
            ]);

            if (! $approved) {
                $locked->update([
                    'status' => PlmChangeOrder::Rejected,
                    'workflow_stage' => EcoWorkflowStage::Rejected->value,
                    'decided_at' => $timestamp,
                    'rejection_reason' => $comment,
                ]);
            } elseif (! PlmChangeApproval::query()->where('change_order_id', $locked->id)->where('status', PlmChangeApproval::Pending)->exists()) {
                $locked->update([
                    'status' => PlmChangeOrder::Approved,
                    'workflow_stage' => EcoWorkflowStage::EcoApproved->value,
                    'decided_at' => $timestamp,
                ]);
            }

            return $this->loaded($locked->refresh());
        });
    }

    public function cancel(PlmChangeOrder $order): PlmChangeOrder
    {
        if (! in_array($order->status, [PlmChangeOrder::Draft, PlmChangeOrder::InReview, PlmChangeOrder::Approved], true)) {
            throw ValidationException::withMessages(['change_order' => [__('plm_errors.order.cannot_cancel')]]);
        }

        $order->update([
            'status' => PlmChangeOrder::Cancelled,
            'workflow_stage' => EcoWorkflowStage::Cancelled->value,
        ]);

        return $this->loaded($order->refresh());
    }

    public function implement(PlmChangeOrder $order): PlmChangeOrder
    {
        return DB::transaction(function () use ($order): PlmChangeOrder {
            $locked = PlmChangeOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PlmChangeOrder::Approved) {
                throw ValidationException::withMessages(['change_order' => [__('plm_errors.order.not_approved')]]);
            }

            $effective = $locked->effective_date?->toDateString() ?? now()->toDateString();

            foreach ($locked->affectedItems()->get() as $affected) {
                $exists = PlmItemRevision::query()->where('mfg_item_id', $affected->mfg_item_id)->where('revision', $affected->to_revision)->exists();

                if ($exists) {
                    throw ValidationException::withMessages(['affected_items' => [__('plm_errors.revision.exists', ['revision' => $affected->to_revision])]]);
                }

                PlmItemRevision::query()
                    ->where('mfg_item_id', $affected->mfg_item_id)
                    ->where('status', PlmItemRevision::Released)
                    ->update([
                        'status' => PlmItemRevision::Obsolete,
                        'lifecycle_state' => 'ObsoleteBlocked',
                        'mes_drawing_obsolete_watermark' => true,
                        'obsoleted_at' => now(),
                    ]);

                PlmItemRevision::create([
                    'tenant_id' => $locked->tenant_id,
                    'mfg_item_id' => $affected->mfg_item_id,
                    'revision' => $affected->to_revision,
                    'status' => PlmItemRevision::Released,
                    'lifecycle_state' => 'ReleasedForProduction',
                    'change_summary' => $affected->change_summary ?? $locked->title,
                    'change_order_id' => $locked->id,
                    'effective_from' => $effective,
                    'released_at' => now(),
                ]);
            }

            $locked->update([
                'status' => PlmChangeOrder::Implemented,
                'workflow_stage' => EcoWorkflowStage::EcnReleased->value,
                'implemented_at' => now(),
                'ecn_number' => $locked->ecn_number ?? 'ECN-'.now()->format('Ymd').'-'.strtoupper(substr((string) $locked->id, 0, 6)),
                'ecn_released_at' => now(),
            ]);

            return $this->loaded($locked->refresh());
        });
    }

    public function loaded(PlmChangeOrder $order): PlmChangeOrder
    {
        return $order->load(['approvals.approver:id,name', 'affectedItems.item:id,item_code,name', 'affectedBoms.bom:id,bom_number,name,revision,status']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        return array_diff_key($data, ['approvers' => true, 'affected_items' => true, 'affected_boms' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncLinks(PlmChangeOrder $order, array $data): void
    {
        if (isset($data['approvers'])) {
            $order->approvals()->delete();

            foreach (array_values($data['approvers']) as $index => $approverId) {
                PlmChangeApproval::create(['tenant_id' => $order->tenant_id, 'change_order_id' => $order->id, 'approver_id' => $approverId, 'sequence' => $index + 1, 'status' => PlmChangeApproval::Pending]);
            }
        }

        if (isset($data['affected_items'])) {
            $order->affectedItems()->delete();

            foreach ($data['affected_items'] as $item) {
                PlmAffectedItem::create([
                    'tenant_id' => $order->tenant_id,
                    'change_order_id' => $order->id,
                    'mfg_item_id' => $item['mfg_item_id'],
                    'to_revision' => $item['to_revision'],
                    'change_summary' => $item['change_summary'] ?? null,
                    'disposition_rule' => $item['disposition_rule'] ?? null,
                    'cutover_mode' => $item['cutover_mode'] ?? 'PhaseOut_UseUpStock',
                    'effective_date' => $item['effective_date'] ?? null,
                    'lot_serial_cutover' => $item['lot_serial_cutover'] ?? null,
                ]);
            }
        }

        if (isset($data['affected_boms'])) {
            $order->affectedBoms()->delete();

            foreach ($data['affected_boms'] as $bom) {
                PlmAffectedBom::create(['tenant_id' => $order->tenant_id, 'change_order_id' => $order->id, 'bom_id' => $bom['bom_id'], 'note' => $bom['note'] ?? null]);
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @return array<string, array<string, mixed>>
     */
    private function defaultApprovalsMatrix(?array $existing): array
    {
        $matrix = [];
        foreach (ApprovalDepartment::cases() as $department) {
            $current = is_array($existing[$department->value] ?? null) ? $existing[$department->value] : [];
            $matrix[$department->value] = [
                'department' => $department->value,
                'status' => $current['status'] ?? 'pending',
                'actor_id' => $current['actor_id'] ?? null,
                'actor_role' => $current['actor_role'] ?? null,
                'decided_at' => $current['decided_at'] ?? null,
                'comment' => $current['comment'] ?? null,
                'sign_off_hash' => $current['sign_off_hash'] ?? null,
            ];
        }

        return $matrix;
    }

    private function assertDraft(PlmChangeOrder $order): void
    {
        if ($order->status !== PlmChangeOrder::Draft) {
            throw ValidationException::withMessages(['change_order' => [__('plm_errors.order.not_draft')]]);
        }
    }

    private function assertCodeFree(string $tenantId, string $code, ?string $ignoreId): void
    {
        $taken = PlmChangeOrder::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->when($ignoreId !== null, static fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['code' => [__('plm_errors.order.code_taken', ['code' => $code])]]);
        }
    }
}
