<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Services;

use App\Models\Contact;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Modules\DebtCollection\Exceptions\DebtCollectionException;
use Modules\DebtCollection\Models\Policy;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\ReceivableItem;

/**
 * The interest and fee breakdown behind a proposal line: what is owed on an open item (or on an amount typed in) on a
 * date, period by period when the rate changed, so the figure can be explained to the customer.
 */
final class CalculationService
{
    public function __construct(
        private readonly ProposalService $proposals,
        private readonly InterestCalculator $calculator,
        private readonly InterestRateResolver $rates,
        private readonly PolicyResolver $policies,
    ) {}

    /**
     * @param  array{receivable_item_id?: string|null, amount?: string|null, due_date?: string|null, currency?: string|null, jurisdiction?: string|null, level?: int|null}  $input
     * @return array<string, mixed>
     */
    public function calculate(string $tenantId, array $input, CarbonInterface $asOf): array
    {
        $asOf = Carbon::instance($asOf)->startOfDay();
        $tenantCountry = (string) (Tenant::query()->whereKey($tenantId)->value('country') ?? 'IT');
        $posted = '0';

        if (! empty($input['receivable_item_id'])) {
            $item = ReceivableItem::query()->findOrFail($input['receivable_item_id']);
            $contact = Contact::query()->findOrFail($item->customer_id);
            $principal = (string) $item->open_amount;
            $dueDate = Carbon::instance($item->due_date);
            $currency = (string) $item->currency;
            $jurisdiction = $this->proposals->jurisdictionOf($contact, $tenantCountry);
            $posted = $this->proposals->postedInterest([(string) $item->id])[(string) $item->id] ?? '0';
            $customerId = (string) $item->customer_id;
        } else {
            $principal = (string) $input['amount'];
            $dueDate = Carbon::parse((string) $input['due_date']);
            $currency = (string) ($input['currency'] ?? 'EUR');
            $jurisdiction = (string) ($input['jurisdiction'] ?? ($tenantCountry === 'CH' ? 'CH' : 'IT'));
            $customerId = '';
        }

        $policy = $this->policies->resolve($jurisdiction, $currency, $customerId)
            ?? Policy::query()->with('levels')->where('jurisdiction', $jurisdiction)->first()
            ?? throw new DebtCollectionException('No dunning policy is configured for this jurisdiction.', 'no_policy');

        $calculation = $this->calculator->calculate(
            Decimal::round2($principal),
            $dueDate,
            $asOf,
            (string) $policy->interest_basis,
            $this->rates->periods($tenantId, $jurisdiction),
            $policy->contractual_rate_percent === null ? null : (string) $policy->contractual_rate_percent,
        );

        $interest = Decimal::sub($calculation['interest'], $posted);
        $interest = Decimal::isNegative($interest) ? '0.00' : Decimal::round2($interest);
        $level = isset($input['level']) ? $policy->levels->firstWhere('level', (int) $input['level']) : null;
        $fee = $level === null ? '0.00' : Decimal::round2((string) $level->fee_amount);

        return [
            'principal' => Decimal::round2($principal),
            'interest' => $interest,
            'fee' => $fee,
            'total' => Decimal::round2(Decimal::add(Decimal::add($principal, $interest), $fee)),
            'interest_days' => $calculation['days'],
            'rate_percent' => $calculation['rate_percent'],
            'basis' => $calculation['basis'],
            'already_posted' => Decimal::round2($posted),
            'segments' => $calculation['segments'],
        ];
    }
}
