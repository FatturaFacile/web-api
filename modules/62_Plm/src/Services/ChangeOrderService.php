<?php

declare(strict_types=1);

namespace Modules\Plm\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Plm\Models\PlmAffectedBom;
use Modules\Plm\Models\PlmAffectedItem;
use Modules\Plm\Models\PlmChangeApproval;
use Modules\Plm\Models\PlmChangeOrder;
use Modules\Plm\Models\PlmItemRevision;

/**
 * Engineering change orders. A draft is freely edited (its approvers, affected items and bills of material are replaced
 * as a whole when sent); submitting starts the approval flow, in which the approvers decide one after the other; an
 * approved order is implemented, which releases the new revision of every affected item and makes the previous
 * released revision obsolete.
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
            $order = PlmChangeOrder::create([...$this->fields($data), 'tenant_id' => $tenantId, 'status' => PlmChangeOrder::Draft, 'requested_by' => $userId]);
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

        $order->update(['status' => PlmChangeOrder::InReview, 'submitted_at' => now()]);

        return $this->loaded($order->refresh());
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

            $step->update(['status' => $approved ? PlmChangeApproval::Approved : PlmChangeApproval::Rejected, 'decided_at' => now(), 'comment' => $comment]);

            if (! $approved) {
                $locked->update(['status' => PlmChangeOrder::Rejected, 'decided_at' => now(), 'rejection_reason' => $comment]);
            } elseif (! PlmChangeApproval::query()->where('change_order_id', $locked->id)->where('status', PlmChangeApproval::Pending)->exists()) {
                $locked->update(['status' => PlmChangeOrder::Approved, 'decided_at' => now()]);
            }

            return $this->loaded($locked->refresh());
        });
    }

    public function cancel(PlmChangeOrder $order): PlmChangeOrder
    {
        if (! in_array($order->status, [PlmChangeOrder::Draft, PlmChangeOrder::InReview, PlmChangeOrder::Approved], true)) {
            throw ValidationException::withMessages(['change_order' => [__('plm_errors.order.cannot_cancel')]]);
        }

        $order->update(['status' => PlmChangeOrder::Cancelled]);

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

                PlmItemRevision::query()->where('mfg_item_id', $affected->mfg_item_id)->where('status', PlmItemRevision::Released)->update(['status' => PlmItemRevision::Obsolete, 'obsoleted_at' => now()]);
                PlmItemRevision::create([
                    'tenant_id' => $locked->tenant_id,
                    'mfg_item_id' => $affected->mfg_item_id,
                    'revision' => $affected->to_revision,
                    'status' => PlmItemRevision::Released,
                    'change_summary' => $affected->change_summary ?? $locked->title,
                    'change_order_id' => $locked->id,
                    'effective_from' => $effective,
                    'released_at' => now(),
                ]);
            }

            $locked->update(['status' => PlmChangeOrder::Implemented, 'implemented_at' => now()]);

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
                PlmAffectedItem::create(['tenant_id' => $order->tenant_id, 'change_order_id' => $order->id, 'mfg_item_id' => $item['mfg_item_id'], 'to_revision' => $item['to_revision'], 'change_summary' => $item['change_summary'] ?? null]);
            }
        }

        if (isset($data['affected_boms'])) {
            $order->affectedBoms()->delete();

            foreach ($data['affected_boms'] as $bom) {
                PlmAffectedBom::create(['tenant_id' => $order->tenant_id, 'change_order_id' => $order->id, 'bom_id' => $bom['bom_id'], 'note' => $bom['note'] ?? null]);
            }
        }
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
