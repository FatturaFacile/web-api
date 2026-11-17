<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use App\Models\Contact;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Modules\SubscriptionBilling\Mail\SubscriptionIndexationNoticeMail;
use Modules\SubscriptionBilling\Models\IndexationAdjustment;
use Modules\SubscriptionBilling\Models\InflationIndex;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Modules\SubscriptionBilling\Support\Money;
use Throwable;

/**
 * Annual price indexation of the recurring fees on the ISTAT FOI (Italy) or BFS LIK (Switzerland) series.
 *
 *   variation % = (index of the reference month / index of the base month - 1) x 100
 *   applied %   = variation % x contractual ratio (e.g. 75% for ISTAT), never below 0 (no decrease) and capped at the
 *                 contractual maximum
 *
 * The adjustment multiplies the price factor of the recurring items (the same mechanism as the renewal price
 * increase), so the next invoices bill the indexed fee; usage charges are not indexed. Each contract cycle is indexed
 * once (unique per subscription, effective date and series) and the customer gets the recalculation sheet by email.
 */
final readonly class IndexationService
{
    public function __construct(
        private SubscriptionHistory $history,
        private PricingCalculator $pricing,
    ) {}

    public function recordIndex(string $type, string $referenceMonth, string|int|float $value, ?string $basePeriod = null, ?string $source = null): InflationIndex
    {
        if (! in_array($type, InflationIndex::TYPES, true)) {
            throw new InvalidArgumentException("Unsupported index type: {$type}");
        }

        if (! Money::isPositive($value)) {
            throw new InvalidArgumentException('The index value must be positive.');
        }

        $month = CarbonImmutable::parse($referenceMonth)->startOfMonth()->toDateString();
        $attributes = ['index_value' => Money::round((string) $value, 4), 'base_period' => $basePeriod, 'source' => $source];
        $index = InflationIndex::query()->where('index_type', $type)->whereDate('reference_month', $month)->first();

        if ($index === null) {
            return InflationIndex::create(['index_type' => $type, 'reference_month' => $month] + $attributes);
        }

        $index->update($attributes);

        return $index;
    }

    /**
     * The recalculation sheet (prospetto di ricalcolo) of the adjustment at the effective date, without saving it.
     *
     * @return array<string, mixed>
     */
    public function preview(Subscription $subscription, ?CarbonInterface $effectiveDate = null): array
    {
        $type = $subscription->indexation_type;

        if ($type === null) {
            throw new InvalidArgumentException('The subscription has no indexation clause.');
        }

        $effective = CarbonImmutable::instance($effectiveDate ?? $subscription->next_indexation_date ?? now())->startOfDay();
        $baseMonth = CarbonImmutable::instance($subscription->indexation_base_month ?? $subscription->start_date ?? $effective)->startOfMonth();

        $baseIndex = InflationIndex::query()->where('index_type', $type)->whereDate('reference_month', $baseMonth->toDateString())->first();

        if ($baseIndex === null) {
            throw new InvalidArgumentException(sprintf('The base %s index of %s is not available.', $type, $baseMonth->format('Y-m')));
        }

        // The latest index published up to the month before the effective date.
        $latestAllowed = $effective->subMonthNoOverflow()->startOfMonth();
        $newIndex = InflationIndex::query()
            ->where('index_type', $type)
            ->whereDate('reference_month', '<=', $latestAllowed->toDateString())
            ->whereDate('reference_month', '>', $baseMonth->toDateString())
            ->orderByDesc('reference_month')
            ->first();

        if ($newIndex === null) {
            throw new InvalidArgumentException(sprintf('No %s index is published after %s up to %s.', $type, $baseMonth->format('Y-m'), $latestAllowed->format('Y-m')));
        }

        $variation = Money::mul(Money::sub(Money::div((string) $newIndex->getRawOriginal('index_value'), (string) $baseIndex->getRawOriginal('index_value')), '1'), '100');
        $ratio = Money::of($subscription->getRawOriginal('indexation_ratio_pct') ?? '100');
        $applied = Money::max('0', Money::div(Money::mul($variation, $ratio), '100'));
        $cap = $subscription->getRawOriginal('indexation_cap_pct');
        $capped = false;

        if ($cap !== null && Money::compare($applied, $cap) > 0) {
            $applied = Money::of($cap);
            $capped = true;
        }

        $applied = Money::round($applied, 4);
        $multiplier = Money::add('1', Money::div($applied, '100'));

        $subscription->loadMissing(['plan.pricingTiers', 'items']);
        $lines = [];

        foreach ($subscription->items as $item) {
            /** @var SubscriptionItem $item */
            if ($item->item_type === 'usage_meter') {
                continue;
            }

            $oldFactor = Money::of($item->getRawOriginal('price_factor') ?? '1');
            $newFactor = Money::round(Money::mul(Money::isZero($oldFactor) ? '1' : $oldFactor, $multiplier), 8);
            $lines[] = [
                'item_id' => (string) $item->id,
                'description' => $item->description,
                'old_amount' => $this->pricing->itemAmount($item, $subscription->plan),
                'new_amount' => $this->amountAtFactor($item, $subscription, $newFactor),
                'old_price_factor' => $oldFactor,
                'new_price_factor' => $newFactor,
            ];
        }

        $oldTotal = Money::sum(array_column($lines, 'old_amount'));
        $newTotal = Money::sum(array_column($lines, 'new_amount'));
        $oldMrr = Money::round2($subscription->getRawOriginal('mrr') ?? '0');

        return [
            'subscription_id' => (string) $subscription->id,
            'subscription_number' => $subscription->subscription_number,
            'index_type' => $type,
            'base_month' => $baseMonth->toDateString(),
            'reference_month' => CarbonImmutable::parse((string) $newIndex->getRawOriginal('reference_month'))->toDateString(),
            'base_index' => Money::round((string) $baseIndex->getRawOriginal('index_value'), 4),
            'new_index' => Money::round((string) $newIndex->getRawOriginal('index_value'), 4),
            'variation_pct' => Money::round($variation, 4),
            'ratio_pct' => Money::round2($ratio),
            'cap_pct' => $cap !== null ? Money::round((string) $cap, 3) : null,
            'cap_applied' => $capped,
            'applied_pct' => $applied,
            'effective_date' => $effective->toDateString(),
            'old_recurring_amount' => Money::round2($oldTotal),
            'new_recurring_amount' => Money::round2($newTotal),
            'old_mrr' => $oldMrr,
            'new_mrr' => Money::round2(Money::mul($oldMrr, $multiplier)),
            'items' => $lines,
        ];
    }

    /**
     * Applies the adjustment of the cycle (idempotent) and, unless told otherwise, notifies the customer.
     */
    public function apply(Subscription $subscription, ?CarbonInterface $effectiveDate = null, ?string $actorUserId = null, bool $notify = true): IndexationAdjustment
    {
        $adjustment = DB::transaction(function () use ($subscription, $effectiveDate, $actorUserId): IndexationAdjustment {
            /** @var Subscription $locked */
            $locked = Subscription::query()->with(['items', 'plan.pricingTiers'])->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $effective = CarbonImmutable::instance($effectiveDate ?? $locked->next_indexation_date ?? now())->startOfDay();

            $existing = IndexationAdjustment::query()
                ->where('subscription_id', $locked->id)
                ->whereDate('effective_date', $effective->toDateString())
                ->where('index_type', $locked->indexation_type)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $sheet = $this->preview($locked, $effective);
            $mrrBefore = Money::round2($locked->getRawOriginal('mrr') ?? '0');

            foreach ($sheet['items'] as $line) {
                $locked->items->firstWhere('id', $line['item_id'])?->update([
                    'price_factor' => $line['new_price_factor'],
                    'subtotal_recurring' => $line['new_amount'],
                ]);
            }

            $locked->unsetRelation('items');
            $locked->forceFill([
                'indexation_base_month' => $sheet['reference_month'],
                'next_indexation_date' => $effective->addYear()->toDateString(),
                'last_indexed_at' => now(),
            ])->save();

            // Paused or closed contracts are indexed too, but carry no MRR.
            [, $mrrAfter] = $this->history->refreshMrr($locked, in_array($locked->status, ['active', 'past_due'], true));

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
                'old_mrr' => $mrrBefore,
                'new_mrr' => $mrrAfter,
                'effective_date' => $sheet['effective_date'],
                'calculation_sheet' => $sheet,
            ]);

            $this->history->version(
                $locked,
                'indexation',
                $mrrBefore,
                $mrrAfter,
                $effective,
                sprintf('Indexation %s %s%%', strtoupper($sheet['index_type']), $sheet['applied_pct']),
                $actorUserId,
                '0',
                ['indexation' => ['adjustment_id' => (string) $adjustment->id, 'applied_pct' => $sheet['applied_pct']]]
            );
            $this->history->audit(
                'SUBSCRIPTION_INDEXED',
                $locked,
                ['recurring_amount' => $sheet['old_recurring_amount']],
                ['recurring_amount' => $sheet['new_recurring_amount'], 'applied_pct' => $sheet['applied_pct'], 'index_type' => $sheet['index_type']]
            );

            return $adjustment;
        });

        if ($notify && $adjustment->wasRecentlyCreated) {
            $this->notifyCustomer($subscription->fresh() ?? $subscription, $adjustment);
        }

        return $adjustment;
    }

    /**
     * Applies every indexation due at the date for the company (the nightly job). A contract whose index is not
     * published yet is skipped and tried again the next night.
     *
     * @return array{applied: int, skipped: array<int, array{subscription_id: string, reason: string}>}
     */
    public function processDue(string $tenantId, ?CarbonInterface $asOf = null): array
    {
        $asOf = CarbonImmutable::instance($asOf ?? now())->startOfDay();
        $applied = 0;
        $skipped = [];

        $due = Subscription::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'past_due', 'paused'])
            ->whereNotNull('indexation_type')
            ->whereDate('next_indexation_date', '<=', $asOf->toDateString())
            ->get();

        foreach ($due as $subscription) {
            try {
                $this->apply($subscription, $subscription->next_indexation_date);
                $applied++;
            } catch (InvalidArgumentException $e) {
                $skipped[] = ['subscription_id' => (string) $subscription->id, 'reason' => $e->getMessage()];
            }
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * Sets, changes or removes (null type) the indexation clause of a contract.
     */
    public function configure(Subscription $subscription, ?string $type, string $ratioPct = '100', ?string $capPct = null, ?CarbonInterface $baseMonth = null, ?CarbonInterface $nextDate = null): Subscription
    {
        if ($type !== null && ! in_array($type, InflationIndex::TYPES, true)) {
            throw new InvalidArgumentException("Unsupported index type: {$type}");
        }

        $subscription->forceFill($type === null
            ? ['indexation_type' => null, 'indexation_base_month' => null, 'next_indexation_date' => null, 'indexation_cap_pct' => null, 'indexation_ratio_pct' => '100']
            : [
                'indexation_type' => $type,
                'indexation_ratio_pct' => $ratioPct,
                'indexation_cap_pct' => $capPct,
                'indexation_base_month' => CarbonImmutable::instance($baseMonth ?? $subscription->start_date ?? now())->startOfMonth()->toDateString(),
                'next_indexation_date' => CarbonImmutable::instance($nextDate ?? CarbonImmutable::instance($subscription->start_date ?? now())->addYear())->toDateString(),
            ])->save();

        return $subscription;
    }

    private function amountAtFactor(SubscriptionItem $item, Subscription $subscription, string $factor): string
    {
        $probe = $item->replicate();
        $probe->setRawAttributes(array_merge($item->getAttributes(), ['price_factor' => $factor]));

        return $this->pricing->itemAmount($probe, $subscription->plan);
    }

    private function notifyCustomer(Subscription $subscription, IndexationAdjustment $adjustment): void
    {
        $contact = Contact::query()->where('tenant_id', $subscription->tenant_id)->find($subscription->customer_id);

        if ($contact === null || empty($contact->email)) {
            return;
        }

        $name = $contact->company_name ?: trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''));

        try {
            Mail::to($contact->email)->send(new SubscriptionIndexationNoticeMail($subscription, $adjustment, $name));
            $adjustment->update(['notified_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('The indexation notice could not be sent.', ['subscription_id' => (string) $subscription->id, 'error' => $e->getMessage()]);
        }
    }
}
