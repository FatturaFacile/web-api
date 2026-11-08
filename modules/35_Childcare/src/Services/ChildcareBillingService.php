<?php

declare(strict_types=1);

namespace Modules\Childcare\Services;

use App\Exceptions\BusinessRuleException;
use App\Services\Invoicing\InvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Childcare\Models\ActivityEnrolment;
use Modules\Childcare\Models\Attendance;
use Modules\Childcare\Models\Child;
use Modules\Childcare\Models\ChildFee;
use Modules\Childcare\Models\MonthlyBill;
use Modules\Childcare\Models\SubsidyRule;
use Modules\Childcare\Models\WeeklyPlan;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The monthly fees: fee plan, meals and activities, less the cantonal subsidy, invoiced to the billing contact of each
 * child through the invoicing flow (a CHF invoice gets its Swiss QR bill there). Invoices carry the reference code of
 * the child, never its name.
 */
final class ChildcareBillingService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly SubsidyCalculator $subsidies,
        private readonly CareService $care,
    ) {}

    /**
     * Bills every active child of the month once. A child whose bill cannot be computed safely is skipped with the
     * reason, so that no wrong invoice goes out.
     *
     * @return array{period: string, billed: list<array<string, mixed>>, skipped: list<array{child_id: string, reference_code: string, reason: string}>}
     */
    public function run(string $period, string $invoiceDate): array
    {
        $start = CarbonImmutable::parse("{$period}-01");
        $end = $start->endOfMonth();
        $result = ['period' => $period, 'billed' => [], 'skipped' => []];

        $children = Child::query()
            ->whereDate('enrolled_on', '<=', $end)
            ->where(fn ($query) => $query->whereNull('left_on')->orWhereDate('left_on', '>=', $start))
            ->orderBy('reference_code')
            ->get();

        foreach ($children as $child) {
            try {
                $bill = DB::transaction(fn (): ?MonthlyBill => $this->billChild($child, $period, $start, $end, $invoiceDate));

                if ($bill !== null) {
                    $result['billed'][] = ['child_id' => $child->id, 'reference_code' => $child->reference_code, 'net_amount' => (string) $bill->net_amount, 'invoice_id' => $bill->invoice_id];
                }
            } catch (BusinessRuleException $exception) {
                $result['skipped'][] = ['child_id' => $child->id, 'reference_code' => $child->reference_code, 'reason' => $exception->getMessage()];
            }
        }

        return $result;
    }

    /**
     * The subsidies of a month, one line per child, for the export to the canton.
     *
     * @return list<array<string, string|int|null>>
     */
    public function subsidyExport(string $period, ?string $canton): array
    {
        $bills = MonthlyBill::query()->where('period', $period)->whereNotNull('subsidy_rule_id')->get();
        $rules = SubsidyRule::query()->whereIn('id', $bills->pluck('subsidy_rule_id'))->get()->keyBy('id');
        $children = Child::query()->whereIn('id', $bills->pluck('child_id'))->get()->keyBy('id');

        return $bills
            ->filter(static fn (MonthlyBill $bill): bool => $canton === null || $rules[$bill->subsidy_rule_id]?->canton === $canton)
            ->map(static fn (MonthlyBill $bill): array => [
                'reference_code' => $children[$bill->child_id]->reference_code,
                'last_name' => $children[$bill->child_id]->last_name,
                'first_name' => $children[$bill->child_id]->first_name,
                'birth_date' => $children[$bill->child_id]->birth_date,
                'canton' => $rules[$bill->subsidy_rule_id]->canton,
                'rule' => $rules[$bill->subsidy_rule_id]->name,
                'source_reference' => $rules[$bill->subsidy_rule_id]->source_reference,
                'period' => $bill->period,
                'attended_days' => $bill->attended_days,
                'gross_amount' => (string) $bill->gross_amount,
                'subsidy_amount' => (string) $bill->subsidy_amount,
            ])
            ->sortBy('reference_code')->values()->all();
    }

    private function billChild(Child $child, string $period, CarbonImmutable $start, CarbonImmutable $end, string $invoiceDate): ?MonthlyBill
    {
        Child::query()->whereKey($child->id)->lockForUpdate()->firstOrFail();

        if (MonthlyBill::query()->where('child_id', $child->id)->where('period', $period)->exists()) {
            return null;
        }

        $fee = ChildFee::query()->with('feePlan')->where('child_id', $child->id)
            ->whereDate('valid_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $start))
            ->orderByDesc('valid_from')->first();

        if ($fee === null) {
            throw new BusinessRuleException('No fee plan for the month.');
        }

        $plan = $fee->feePlan;
        $presentDays = Attendance::query()->where('child_id', $child->id)->where('status', 'present')->whereBetween('date', [$start->toDateString(), $end->toDateString()])->pluck('date');
        $weeklyPlans = WeeklyPlan::query()->where('child_id', $child->id)->get();
        $meals = $presentDays->sum(fn (CarbonImmutable $day): int => count($this->care->planOn($child, $day, $weeklyPlans)?->dayOf($day)['meals'] ?? []));
        $reference = "{$child->reference_code} {$period}";
        $lines = [];

        if ($plan->basis === 'monthly_flat') {
            $lines[] = ['description' => "Fee {$reference}", 'quantity' => '1', 'unit_price' => (string) $plan->amount, 'tax_rate' => (string) $plan->tax_rate];
        } elseif ($presentDays->isNotEmpty()) {
            $lines[] = ['description' => "Days of care {$reference}", 'quantity' => (string) $presentDays->count(), 'unit_price' => (string) $plan->amount, 'tax_rate' => (string) $plan->tax_rate];
        }

        if ($meals > 0 && Decimal::isPositive((string) $plan->meal_amount)) {
            $lines[] = ['description' => "Meals {$reference}", 'quantity' => (string) $meals, 'unit_price' => (string) $plan->meal_amount, 'tax_rate' => (string) $plan->tax_rate];
        }

        $enrolments = ActivityEnrolment::query()->with('activity')->where('child_id', $child->id)
            ->whereDate('valid_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $start))->get();

        foreach ($enrolments as $enrolment) {
            if (Decimal::isPositive((string) $enrolment->activity->monthly_fee)) {
                $lines[] = ['description' => "{$enrolment->activity->name} {$reference}", 'quantity' => '1', 'unit_price' => (string) $enrolment->activity->monthly_fee, 'tax_rate' => (string) $enrolment->activity->tax_rate];
            }
        }

        $gross = array_reduce($lines, static fn (string $sum, array $line): string => Decimal::add($sum, Decimal::round2(Decimal::mul($line['quantity'], $line['unit_price']))), '0');
        [$rule, $subsidy] = $this->subsidyFor($child, $start, $presentDays->count(), Decimal::round2($gross));

        if (Decimal::isPositive($subsidy)) {
            $lines[] = ['description' => "Subsidy {$rule?->canton} {$reference}", 'quantity' => '1', 'unit_price' => Decimal::round2(Decimal::neg($subsidy)), 'tax_rate' => (string) $plan->tax_rate];
        }

        $net = Decimal::round2(Decimal::sub($gross, $subsidy));
        $invoiceId = null;

        if (Decimal::isPositive($net)) {
            $invoiceId = (string) $this->invoices->createInvoice([
                'contact_id' => $child->billing_contact_id,
                'invoice_type' => 'standard',
                'date' => $invoiceDate,
                'currency' => $plan->currency,
                'notes' => "Childcare {$reference}",
                'items' => $lines,
            ])->id;
        }

        $bill = new MonthlyBill;
        $bill->fill([
            'child_id' => $child->id,
            'period' => $period,
            'attended_days' => $presentDays->count(),
            'meals' => $meals,
            'gross_amount' => Decimal::round2($gross),
            'subsidy_amount' => $subsidy,
            'net_amount' => $net,
            'subsidy_rule_id' => $rule?->id,
            'invoice_id' => $invoiceId,
        ]);
        $bill->save();

        return $bill;
    }

    /**
     * @return array{0: ?SubsidyRule, 1: string}
     */
    private function subsidyFor(Child $child, CarbonImmutable $start, int $attendedDays, string $gross): array
    {
        if ($child->subsidy_canton === null) {
            return [null, '0.00'];
        }

        $rule = SubsidyRule::query()->where('canton', $child->subsidy_canton)
            ->whereDate('valid_from', '<=', $start)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $start))
            ->orderByDesc('valid_from')->first();

        if ($rule === null) {
            throw new BusinessRuleException("No subsidy rule of canton {$child->subsidy_canton} for the month.");
        }

        return [$rule, $this->subsidies->calculate($rule, $child, $attendedDays, $gross)];
    }
}
