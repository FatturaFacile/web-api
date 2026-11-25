<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Services;

use Illuminate\Support\Facades\DB;
use Modules\DebtCollection\Exceptions\DebtCollectionException;
use Modules\DebtCollection\Models\Exclusion;
use Modules\DebtCollection\Models\PaymentPlan;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\ReceivableItem;

/**
 * Manual exclusions from dunning (customer dispute, legal hold, other) and payment plans (piano di rientro). Both are
 * audited with who and why, and both can be lifted. While one is in force the proposal engine lists the items as
 * excluded and never proposes a notice for them.
 */
final class ExclusionService
{
    public const array Types = ['manual', 'dispute', 'legal_hold'];

    public const array PlanStatuses = ['active', 'completed', 'defaulted', 'cancelled'];

    public function __construct(private readonly HistoryRecorder $history) {}

    public function exclude(string $customerId, ?string $itemId, string $type, string $reason, ?string $notes, ?string $actorId): Exclusion
    {
        if (! in_array($type, self::Types, true)) {
            throw new DebtCollectionException("Unknown exclusion type [{$type}].", 'invalid_exclusion_type');
        }

        if (trim($reason) === '') {
            throw new DebtCollectionException('A reason is required.', 'reason_required');
        }

        if ($itemId !== null && ! ReceivableItem::query()->whereKey($itemId)->where('customer_id', $customerId)->exists()) {
            throw new DebtCollectionException('The item does not belong to the customer.', 'item_not_found', 404);
        }

        $exclusion = Exclusion::create(['customer_id' => $customerId, 'receivable_item_id' => $itemId, 'type' => $type, 'reason' => $reason, 'notes' => $notes, 'created_by' => $actorId]);
        $this->history->record($customerId, 'exclusion_created', 'exclusion', (string) $exclusion->id, null, null, null, ['type' => $type, 'reason' => $reason, 'item_id' => $itemId], $actorId);

        return $exclusion;
    }

    public function release(Exclusion $exclusion, string $note, ?string $actorId): Exclusion
    {
        if ($exclusion->released_at !== null) {
            throw new DebtCollectionException('The exclusion was already released.', 'exclusion_already_released');
        }

        if (trim($note) === '') {
            throw new DebtCollectionException('A note is required to release an exclusion.', 'reason_required');
        }

        $exclusion->update(['released_at' => now(), 'released_by' => $actorId, 'release_note' => $note]);
        $this->history->record((string) $exclusion->customer_id, 'exclusion_already_released', 'exclusion', (string) $exclusion->id, null, null, null, ['type' => $exclusion->type, 'note' => $note], $actorId);

        return $exclusion->refresh();
    }

    /**
     * @param  array{customer_id: string, currency: string, total_amount: string, start_date: string, end_date: string, receivable_item_ids?: list<string>, installments?: list<array{due_date: string, amount: string}>, notes?: string|null}  $data
     */
    public function createPlan(array $data, ?string $actorId): PaymentPlan
    {
        $installments = $data['installments'] ?? [];
        $sum = '0';

        foreach ($installments as $installment) {
            $sum = Decimal::add($sum, $installment['amount']);
        }

        if ($installments !== [] && Decimal::compare($sum, $data['total_amount']) !== 0) {
            throw new DebtCollectionException('The installments must add up to the total of the plan.', 'plan_invalid_schedule');
        }

        return DB::transaction(function () use ($data, $actorId, $installments): PaymentPlan {
            $plan = PaymentPlan::create([
                'customer_id' => $data['customer_id'],
                'currency' => $data['currency'],
                'total_amount' => Decimal::round2($data['total_amount']),
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'status' => 'active',
                'receivable_item_ids' => $data['receivable_item_ids'] ?? [],
                'installments' => $installments,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);
            $this->history->record($data['customer_id'], 'plan_created', 'payment_plan', (string) $plan->id, null, (string) $plan->total_amount, $data['currency'], ['end_date' => $data['end_date']], $actorId);

            return $plan;
        });
    }

    public function transitionPlan(PaymentPlan $plan, string $to, ?string $actorId): PaymentPlan
    {
        if ($plan->status !== 'active') {
            throw new DebtCollectionException('Only an active plan can be closed.', 'plan_not_active');
        }

        if (! in_array($to, ['completed', 'defaulted', 'cancelled'], true)) {
            throw new DebtCollectionException("Unknown plan status [{$to}].", 'invalid_plan_status');
        }

        $plan->update(['status' => $to]);
        $this->history->record((string) $plan->customer_id, 'plan_'.$to, 'payment_plan', (string) $plan->id, null, (string) $plan->total_amount, (string) $plan->currency, [], $actorId);

        return $plan->refresh();
    }
}
