<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use App\Models\Contact;
use Carbon\Carbon;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Modules\SubscriptionBilling\Events\ARRUpdatedEvent;
use Modules\SubscriptionBilling\Events\MRRUpdatedEvent;
use Modules\SubscriptionBilling\Mail\SubscriptionIndexationNoticeMail;
use Modules\SubscriptionBilling\Models\IndexationAdjustment;
use Modules\SubscriptionBilling\Models\InflationIndex;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionVersion;

/**
 * Annual automatic price indexation of recurring fees on ISTAT FOI (IT) or BFS LIK (CH) series.
 *
 * variation% = (index(reference month) / index(base month) - 1) x 100
 * applied%   = variation% x contractual ratio (e.g. 75% ISTAT), floored at 0 and capped at the contract cap.
 */
final readonly class IndexationService
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
    ) {}

    public function recordIndex(string $type, string $referenceMonth, float $value, ?string $basePeriod = null, ?string $source = null): InflationIndex
    {
        if (! in_array($type, InflationIndex::TYPES, true)) {
            throw new InvalidArgumentException("Unsupported index type: {$type}");
        }

        if ($value <= 0) {
            throw new InvalidArgumentException('Index value must be positive.');
        }

        $month = Carbon::parse($referenceMonth)->startOfMonth()->toDateString();
        $attributes = ['index_value' => $value, 'base_period' => $basePeriod, 'source' => $source];

        $index = InflationIndex::where('index_type', $type)->whereDate('reference_month', $month)->first();
        if ($index === null) {
            return InflationIndex::create(['index_type' => $type, 'reference_month' => $month] + $attributes);
        }

        $index->update($attributes);

        return $index;
    }

    /**
     * Recalculation sheet (prospetto di ricalcolo) without persisting anything.
     *
     * @return array<string, mixed>
     */
    public function preview(Subscription $subscription, ?Carbon $effectiveDate = null): array
    {
        $type = $subscription->indexation_type;

        if ($type === null) {
            throw new InvalidArgumentException('Subscription has no indexation clause.');
        }

        $effectiveDate = ($effectiveDate ?? Carbon::parse($subscription->next_indexation_date ?? now()))->copy()->startOfDay();
        $baseMonth = Carbon::parse($subscription->indexation_base_month ?? $subscription->start_date)->startOfMonth();

        $baseIndex = InflationIndex::where('index_type', $type)->whereDate('reference_month', $baseMonth->toDateString())->first();
        if ($baseIndex === null) {
            throw new InvalidArgumentException(sprintf('Base %s index for %s is not available.', $type, $baseMonth->format('Y-m')));
        }

        // Latest published index up to the month preceding the effective date.
        $latestAllowed = $effectiveDate->copy()->subMonthNoOverflow()->startOfMonth();
        $newIndex = InflationIndex::where('index_type', $type)
            ->whereDate('reference_month', '<=', $latestAllowed->toDateString())
            ->whereDate('reference_month', '>', $baseMonth->toDateString())
            ->orderByDesc('reference_month')
            ->first();

        if ($newIndex === null) {
            throw new InvalidArgumentException(sprintf('No %s index published after %s up to %s.', $type, $baseMonth->format('Y-m'), $latestAllowed->format('Y-m')));
        }

        $variation = round(((float) $newIndex->index_value / (float) $baseIndex->index_value - 1) * 100, 4);
        $ratio = (float) ($subscription->indexation_ratio_pct ?? 100);
        $applied = max(0.0, $variation * $ratio / 100);
        $capped = false;

        if ($subscription->indexation_cap_pct !== null && $applied > (float) $subscription->indexation_cap_pct) {
            $applied = (float) $subscription->indexation_cap_pct;
            $capped = true;
        }
        $applied = round($applied, 4);
        $multiplier = 1 + $applied / 100;

        $itemLines = [];
        $oldTotal = 0.0;
        $newTotal = 0.0;

        foreach ($subscription->items as $item) {
            if ($item->item_type === 'usage_meter') {
                continue;
            }

            $old = (float) $item->subtotal_recurring;
            $new = round($old * $multiplier, 2);
            $oldTotal += $old;
            $newTotal += $new;
            $itemLines[] = [
                'item_id' => $item->id,
                'description' => $item->description,
                'old_unit_price' => (float) $item->unit_price,
                'new_unit_price' => round((float) $item->unit_price * $multiplier, 4),
                'old_amount' => $old,
                'new_amount' => $new,
            ];
        }

        $oldMrr = (float) $subscription->mrr;

        return [
            'subscription_id' => $subscription->id,
            'subscription_number' => $subscription->subscription_number,
            'index_type' => $type,
            'base_month' => $baseMonth->toDateString(),
            'reference_month' => Carbon::parse($newIndex->reference_month)->toDateString(),
            'base_index' => (float) $baseIndex->index_value,
            'new_index' => (float) $newIndex->index_value,
            'variation_pct' => $variation,
            'ratio_pct' => $ratio,
            'cap_pct' => $subscription->indexation_cap_pct !== null ? (float) $subscription->indexation_cap_pct : null,
            'cap_applied' => $capped,
            'applied_pct' => $applied,
            'effective_date' => $effectiveDate->toDateString(),
            'old_recurring_amount' => round($oldTotal, 2),
            'new_recurring_amount' => round($newTotal, 2),
            'old_mrr' => $oldMrr,
            'new_mrr' => round($oldMrr * $multiplier, 2),
            'items' => $itemLines,
        ];
    }

    public function apply(Subscription $subscription, ?Carbon $effectiveDate = null, ?string $actorUserId = null, bool $notify = true): IndexationAdjustment
    {
        $adjustment = DB::transaction(function () use ($subscription, $effectiveDate, $actorUserId): IndexationAdjustment {
            $locked = Subscription::with('items')->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $effective = ($effectiveDate ?? Carbon::parse($locked->next_indexation_date ?? now()))->copy()->startOfDay();

            // Idempotent per contract cycle: the same effective date is never indexed twice.
            $existing = IndexationAdjustment::where('subscription_id', $locked->id)
                ->whereDate('effective_date', $effective->toDateString())
                ->where('index_type', $locked->indexation_type)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $sheet = $this->preview($locked, $effective);

            foreach ($sheet['items'] as $line) {
                $locked->items->firstWhere('id', $line['item_id'])?->update([
                    'unit_price' => $line['new_unit_price'],
                    'subtotal_recurring' => $line['new_amount'],
                ]);
            }

            $oldMrr = (float) $locked->mrr;
            $oldArr = (float) $locked->arr;
            $newMrr = (float) $sheet['new_mrr'];
            $newArr = round($newMrr * 12, 2);

            $locked->update([
                'mrr' => $newMrr,
                'arr' => $newArr,
                'indexation_base_month' => $sheet['reference_month'],
                'next_indexation_date' => Carbon::parse($sheet['effective_date'])->addYear()->toDateString(),
                'last_indexed_at' => now(),
            ]);

            $adjustment = IndexationAdjustment::create([
                'tenant_id' => $locked->tenant_id,
                'company_id' => $locked->company_id,
                'subscription_id' => $locked->id,
                'index_type' => $sheet['index_type'],
                'base_month' => $sheet['base_month'],
                'reference_month' => $sheet['reference_month'],
                'base_index' => $sheet['base_index'],
                'new_index' => $sheet['new_index'],
                'variation_pct' => $sheet['variation_pct'],
                'applied_pct' => $sheet['applied_pct'],
                'old_recurring_amount' => $sheet['old_recurring_amount'],
                'new_recurring_amount' => $sheet['new_recurring_amount'],
                'old_mrr' => $oldMrr,
                'new_mrr' => $newMrr,
                'effective_date' => $sheet['effective_date'],
                'status' => 'applied',
                'calculation_sheet' => $sheet,
            ]);

            SubscriptionVersion::create([
                'tenant_id' => $locked->tenant_id,
                'subscription_id' => $locked->id,
                'version_number' => (int) $locked->versions()->max('version_number') + 1,
                'effective_date' => $sheet['effective_date'],
                'change_type' => 'amendment',
                'mrr_before' => $oldMrr,
                'mrr_after' => $newMrr,
                'mrr_delta' => round($newMrr - $oldMrr, 2),
                'proration_amount' => 0.00,
                'snapshot_payload' => $locked->toArray(),
                'change_reason' => sprintf('Indexation %s %s%%', strtoupper($sheet['index_type']), $sheet['applied_pct']),
                'actor_user_id' => $actorUserId,
            ]);

            $this->auditLedger->record(
                'SUBSCRIPTION_INDEXED',
                $locked,
                ['recurring' => $sheet['old_recurring_amount']],
                ['recurring' => $sheet['new_recurring_amount'], 'applied_pct' => $sheet['applied_pct']],
                'MODULE_19_SUB'
            );

            $companyId = (string) ($locked->company_id ?? $locked->tenant_id);
            $this->dispatcher->dispatch(new MRRUpdatedEvent(
                subscriptionId: $locked->id,
                oldMrr: $oldMrr,
                newMrr: $newMrr,
                mrrDelta: round($newMrr - $oldMrr, 2),
                tenantId: $locked->tenant_id,
                companyId: $companyId
            ));
            $this->dispatcher->dispatch(new ARRUpdatedEvent(
                subscriptionId: $locked->id,
                oldArr: $oldArr,
                newArr: $newArr,
                arrDelta: round($newArr - $oldArr, 2),
                tenantId: $locked->tenant_id,
                companyId: $companyId
            ));

            return $adjustment;
        });

        if ($notify) {
            $this->notifyCustomer($subscription->fresh() ?? $subscription, $adjustment);
        }

        return $adjustment;
    }

    /**
     * Applies every due indexation of the tenant (nightly automation).
     *
     * @return array{applied: int, skipped: array<int, array{subscription_id: string, reason: string}>}
     */
    public function processDueIndexations(string $tenantId, ?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();
        $applied = 0;
        $skipped = [];

        Subscription::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereNotNull('indexation_type')
            ->whereDate('next_indexation_date', '<=', $asOf->toDateString())
            ->get()
            ->each(function (Subscription $subscription) use (&$applied, &$skipped): void {
                try {
                    $this->apply($subscription, Carbon::parse($subscription->next_indexation_date));
                    $applied++;
                } catch (InvalidArgumentException $e) {
                    // Index not yet published: retried on the next nightly run.
                    $skipped[] = ['subscription_id' => $subscription->id, 'reason' => $e->getMessage()];
                }
            });

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    private function notifyCustomer(Subscription $subscription, IndexationAdjustment $adjustment): void
    {
        if ($adjustment->notified_at !== null) {
            return;
        }

        $contact = Contact::withoutGlobalScopes()->find($subscription->customer_id);
        $email = $contact?->email;

        if (empty($email)) {
            return;
        }

        $name = $contact->company_name ?: trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''));

        try {
            Mail::to($email)->send(new SubscriptionIndexationNoticeMail($subscription, $adjustment, $name));
            $adjustment->update(['notified_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('Indexation notice could not be sent', ['subscription_id' => $subscription->id, 'error' => $e->getMessage()]);
        }
    }
}
