<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Services;

use App\Models\Contact;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\DebtCollection\Models\DunningCase;
use Modules\DebtCollection\Models\Exclusion;
use Modules\DebtCollection\Models\Notice;
use Modules\DebtCollection\Models\NoticeLine;
use Modules\DebtCollection\Models\PaymentPlan;
use Modules\DebtCollection\Models\Policy;
use Modules\DebtCollection\Models\PolicyLevel;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\ReceivableItem;

/**
 * The automatic dunning proposal: scans the open items, finds the policy of each customer and the next level due
 * from the days overdue, the waiting time since the last notice and the deadline it granted, prices interest and fee
 * to the cent, and leaves out (but still lists, flagged) what must not be dunned: items in dispute, customers under a
 * payment plan, legal holds and manual exclusions.
 *
 * Interest already posted to the customer ledger by an earlier notice is deducted, so it is never charged twice.
 */
final class ProposalService
{
    public const string Proposed = 'proposed';

    public const string Excluded = 'excluded';

    public const string Waiting = 'waiting';

    /** Document type of the ledger item that carries the interest and fees of a notice. */
    public const string ChargeDocumentType = 'dunning_charge';

    public function __construct(
        private readonly PolicyResolver $policies,
        private readonly InterestRateResolver $rates,
        private readonly InterestCalculator $calculator,
    ) {}

    /**
     * @param  array{jurisdiction?: string|null, currency?: string|null, customer_id?: string|null, level?: int|null, include_excluded?: bool, search?: string|null, item_ids?: list<string>|null}  $filters
     * @return array{items: list<array<string, mixed>>, groups: list<array<string, mixed>>}
     */
    public function build(string $tenantId, array $filters, CarbonInterface $asOf): array
    {
        $asOf = Carbon::instance($asOf)->startOfDay();
        $tenantCountry = (string) (Tenant::query()->whereKey($tenantId)->value('country') ?? 'IT');

        $items = ReceivableItem::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [ReceivableItem::StatusOpen, ReceivableItem::StatusPartiallyPaid, ReceivableItem::StatusDisputed])
            ->where('open_amount', '>', 0)
            ->where('document_type', '!=', self::ChargeDocumentType)
            ->whereDate('due_date', '<=', $asOf->copy()->addDays(60)->toDateString())
            ->when(! empty($filters['customer_id']), static fn ($query) => $query->where('customer_id', $filters['customer_id']))
            ->when(! empty($filters['currency']), static fn ($query) => $query->where('currency', $filters['currency']))
            ->when(! empty($filters['item_ids']), static fn ($query) => $query->whereIn('id', $filters['item_ids']))
            ->orderBy('due_date')
            ->get();

        $contacts = Contact::query()->whereIn('id', $items->pluck('customer_id')->unique()->all())->get()->keyBy('id');
        $cases = DunningCase::query()->whereIn('receivable_item_id', $items->pluck('id')->all())->get()->keyBy('receivable_item_id');
        $exclusions = Exclusion::query()->whereNull('released_at')->get();
        $plans = PaymentPlan::query()->where('status', 'active')->get();
        $posted = $this->postedInterest($items->pluck('id')->all());

        $rows = [];

        foreach ($items as $item) {
            /** @var Contact|null $contact */
            $contact = $contacts->get($item->customer_id);

            if ($contact === null) {
                continue;
            }

            $row = $this->evaluate($tenantId, $tenantCountry, $item, $contact, $cases->get($item->id), $exclusions, $plans, $posted[(string) $item->id] ?? '0', $asOf);

            if ($row === null) {
                continue;
            }

            if (! empty($filters['jurisdiction']) && $row['jurisdiction'] !== $filters['jurisdiction']) {
                continue;
            }

            if (isset($filters['level']) && $row['proposed_level'] !== (int) $filters['level']) {
                continue;
            }

            if ($row['status'] === self::Excluded && empty($filters['include_excluded'])) {
                continue;
            }

            if (! empty($filters['search']) && ! str_contains(mb_strtolower($row['customer_name'].' '.$row['document_number']), mb_strtolower((string) $filters['search']))) {
                continue;
            }

            $rows[] = $row;
        }

        return ['items' => $rows, 'groups' => $this->groups($rows, $contacts, $asOf)];
    }

    /**
     * @param  Collection<int, Exclusion>  $exclusions
     * @param  Collection<int, PaymentPlan>  $plans
     * @return array<string, mixed>|null
     */
    private function evaluate(string $tenantId, string $tenantCountry, ReceivableItem $item, Contact $contact, ?DunningCase $case, Collection $exclusions, Collection $plans, string $alreadyPosted, Carbon $asOf): ?array
    {
        $jurisdiction = $this->jurisdictionOf($contact, $tenantCountry);
        $policy = $this->policies->resolve($jurisdiction, (string) $item->currency, (string) $item->customer_id);
        $daysOverdue = (int) $item->due_date->copy()->startOfDay()->diffInDays($asOf, false);
        $currentLevel = $case?->current_level;

        $base = [
            'receivable_item_id' => (string) $item->id,
            'case_id' => $case?->id,
            'customer_id' => (string) $item->customer_id,
            'customer_name' => $this->nameOf($contact),
            'document_number' => (string) $item->document_number,
            'due_date' => $item->due_date->toDateString(),
            'currency' => (string) $item->currency,
            'jurisdiction' => $jurisdiction,
            'open_amount' => Decimal::round2((string) $item->open_amount),
            'days_overdue' => $daysOverdue,
            'current_level' => $currentLevel,
            'proposed_level' => null,
            'status' => self::Waiting,
            'exclusion_reason' => null,
            'waiting_until' => null,
            'interest_amount' => '0.00',
            'interest_days' => 0,
            'interest_rate_percent' => '0.0000',
            'fee_amount' => '0.00',
            'total' => Decimal::round2((string) $item->open_amount),
            'policy_id' => $policy?->id,
        ];

        if ($policy === null) {
            return null;
        }

        $next = $this->nextLevel($policy, $currentLevel);
        $due = $next !== null && $daysOverdue >= $next->min_days_overdue;

        if (! $due && $daysOverdue <= 0) {
            return null;
        }

        $base['proposed_level'] = $next?->level;

        $reason = $this->exclusionReason($item, $exclusions, $plans);

        if ($reason === null && Decimal::compare((string) $item->open_amount, (string) $policy->min_overdue_amount) < 0) {
            $reason = 'below_threshold';
        }

        if ($reason !== null) {
            $base['status'] = self::Excluded;
            $base['exclusion_reason'] = $reason;

            return $base;
        }

        if ($next === null || ! $due) {
            return null;
        }

        $waitingUntil = $this->waitingUntil($case, $next);

        if ($waitingUntil !== null && $waitingUntil->gt($asOf)) {
            $base['waiting_until'] = $waitingUntil->toDateString();

            return $base;
        }

        $interest = '0.00';

        if ($next->charge_interest && $daysOverdue > 0) {
            $priced = $this->priceInterest($tenantId, $item, $policy, $jurisdiction, $asOf, $alreadyPosted);
            $interest = $priced['interest'];
            $base['interest_days'] = $priced['days'];
            $base['interest_rate_percent'] = $priced['rate_percent'];
        }

        $base['status'] = self::Proposed;
        $base['interest_amount'] = $interest;
        $base['fee_amount'] = Decimal::round2((string) $next->fee_amount);
        $base['total'] = Decimal::round2(Decimal::add((string) $item->open_amount, $interest));

        return $base;
    }

    /**
     * Interest due on an item up to `$asOf`, less what an earlier notice already posted to the ledger.
     *
     * @return array{interest: string, days: int, rate_percent: string, calculation: array<string, mixed>}
     */
    public function priceInterest(string $tenantId, ReceivableItem $item, Policy $policy, string $jurisdiction, CarbonInterface $asOf, string $alreadyPosted): array
    {
        $calculation = $this->calculator->calculate(
            (string) $item->open_amount,
            $item->due_date,
            $asOf,
            (string) $policy->interest_basis,
            $this->rates->periods($tenantId, $jurisdiction),
            $policy->contractual_rate_percent === null ? null : (string) $policy->contractual_rate_percent,
        );
        $interest = Decimal::sub($calculation['interest'], $alreadyPosted);

        return [
            'interest' => Decimal::isNegative($interest) ? '0.00' : Decimal::round2($interest),
            'days' => $calculation['days'],
            'rate_percent' => $calculation['rate_percent'],
            'calculation' => $calculation,
        ];
    }

    private function nextLevel(Policy $policy, ?int $current): ?PolicyLevel
    {
        foreach ($policy->levels as $level) {
            if ($level->level > ($current ?? -1)) {
                return $level;
            }
        }

        return null;
    }

    private function waitingUntil(?DunningCase $case, PolicyLevel $next): ?Carbon
    {
        if ($case === null || $case->last_notice_on === null) {
            return null;
        }

        $until = $case->last_notice_on->copy()->addDays($next->wait_days);

        if ($case->deadline_on !== null && $case->deadline_on->copy()->addDay()->gt($until)) {
            $until = $case->deadline_on->copy()->addDay();
        }

        return $until;
    }

    /**
     * @param  Collection<int, Exclusion>  $exclusions
     * @param  Collection<int, PaymentPlan>  $plans
     */
    private function exclusionReason(ReceivableItem $item, Collection $exclusions, Collection $plans): ?string
    {
        if ($item->status === ReceivableItem::StatusDisputed) {
            return 'dispute';
        }

        foreach ($exclusions as $exclusion) {
            if ($exclusion->customer_id === $item->customer_id && ($exclusion->receivable_item_id === null || $exclusion->receivable_item_id === $item->id)) {
                return $exclusion->type;
            }
        }

        foreach ($plans as $plan) {
            $covered = (array) $plan->receivable_item_ids;

            if ($plan->customer_id === $item->customer_id && ($covered === [] || in_array((string) $item->id, $covered, true))) {
                return 'payment_plan';
            }
        }

        return null;
    }

    /**
     * Debtors in Switzerland and Liechtenstein fall under the Swiss rules, the others under the Italian ones; a debtor
     * elsewhere follows the country of the creditor.
     */
    public function jurisdictionOf(Contact $contact, string $tenantCountry): string
    {
        $country = strtoupper((string) $contact->country);

        return match (true) {
            in_array($country, ['CH', 'LI'], true) => 'CH',
            in_array($country, ['IT', 'SM', 'VA'], true) => 'IT',
            default => in_array(strtoupper($tenantCountry), ['CH', 'LI'], true) ? 'CH' : 'IT',
        };
    }

    public function nameOf(Contact $contact): string
    {
        $name = trim((string) $contact->company_name) !== '' ? (string) $contact->company_name : trim($contact->first_name.' '.$contact->last_name);

        return $name !== '' ? $name : '-';
    }

    /**
     * @param  list<string>  $itemIds
     * @return array<string, string> interest already posted to the ledger per item
     */
    public function postedInterest(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $posted = [];
        $lines = NoticeLine::query()
            ->whereIn('receivable_item_id', $itemIds)
            ->whereHas('notice', static fn ($query) => $query->whereNotNull('charge_receivable_item_id')->where('status', '!=', 'cancelled'))
            ->get(['receivable_item_id', 'interest']);

        foreach ($lines as $line) {
            $posted[(string) $line->receivable_item_id] = Decimal::add($posted[(string) $line->receivable_item_id] ?? '0', (string) $line->interest);
        }

        return $posted;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int|string, Contact>  $contacts
     * @return list<array<string, mixed>>
     */
    private function groups(array $rows, Collection $contacts, Carbon $asOf): array
    {
        $groups = [];

        foreach ($rows as $row) {
            if ($row['status'] !== self::Proposed) {
                continue;
            }

            $key = $row['customer_id'].':'.$row['currency'].':'.$row['proposed_level'];
            $policy = $this->policies->resolve((string) $row['jurisdiction'], (string) $row['currency'], (string) $row['customer_id']);
            $level = $policy?->levels->firstWhere('level', $row['proposed_level']);
            /** @var Contact $contact */
            $contact = $contacts->get($row['customer_id']);

            $groups[$key] ??= [
                'key' => $key,
                'customer_id' => $row['customer_id'],
                'customer_name' => $row['customer_name'],
                'currency' => $row['currency'],
                'jurisdiction' => $row['jurisdiction'],
                'level' => $row['proposed_level'],
                'receivable_item_ids' => [],
                'principal' => '0.00',
                'interest' => '0.00',
                'fee' => Decimal::round2((string) ($level->fee_amount ?? '0')),
                'total' => '0.00',
                'new_due_date' => $asOf->copy()->addDays((int) ($level->deadline_days ?? 0))->toDateString(),
                'channels' => $level->channels ?? [],
                'has_pec' => trim((string) $contact->pec) !== '',
                'has_email' => trim((string) $contact->email) !== '',
                'has_phone' => trim((string) $contact->phone) !== '',
            ];

            $groups[$key]['receivable_item_ids'][] = $row['receivable_item_id'];
            $groups[$key]['principal'] = Decimal::round2(Decimal::add($groups[$key]['principal'], (string) $row['open_amount']));
            $groups[$key]['interest'] = Decimal::round2(Decimal::add($groups[$key]['interest'], (string) $row['interest_amount']));
        }

        foreach ($groups as &$group) {
            $group['total'] = Decimal::round2(Decimal::add(Decimal::add($group['principal'], $group['interest']), $group['fee']));
        }

        unset($group);

        return array_values($groups);
    }
}
