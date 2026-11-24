<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\ProgressBilling;
use App\Services\Invoicing\InvoiceService;
use App\Services\Sales\DocumentNumberingService;
use Illuminate\Support\Facades\DB;
use Modules\ConstructionTrades\Models\Bid;
use Modules\ConstructionTrades\Models\BidPosition;
use Modules\ConstructionTrades\Models\Billing;
use Modules\ConstructionTrades\Models\Measurement;
use Modules\ConstructionTrades\Models\PriceItem;
use Modules\ConstructionTrades\Models\SiteCost;
use Modules\ConstructionTrades\Models\WorkCycle;
use Modules\ConstructionTrades\Models\WorkCycleStep;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Bids and their positions, the settlement of the measured quantities, partial and final billings, and the control of
 * the budget of each position against the site costs. Amounts are decimal strings computed with bcmath.
 */
final class TradesService
{
    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly InvoiceService $invoices,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createBid(array $data): Bid
    {
        $bid = new Bid;
        $bid->fill([...$data, 'bid_number' => $this->numbering->next('trade_bid')['number'], 'status' => 'draft']);
        $bid->save();

        return $bid;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateBid(Bid $bid, array $data): Bid
    {
        return DB::transaction(function () use ($bid, $data): Bid {
            $locked = $this->lock($bid);
            $this->assertDraft($locked);
            $locked->update($data);

            return $locked;
        });
    }

    /**
     * Moves the bid along its workflow (see Bid::Transitions); a bid is submitted only with positions.
     */
    public function transition(Bid $bid, string $target): Bid
    {
        return DB::transaction(function () use ($bid, $target): Bid {
            $locked = $this->lock($bid);

            if (! in_array($target, Bid::Transitions[$locked->status] ?? [], true)) {
                throw new BusinessRuleException("A bid cannot move from {$locked->status} to {$target}.");
            }

            if ($target === 'submitted' && ! $locked->positions()->exists()) {
                throw new BusinessRuleException('A bid without positions cannot be submitted.');
            }

            $locked->update(['status' => $target]);

            return $locked;
        });
    }

    /**
     * Adds a position, manual or from a price list item. Prices default to the item, budgets come from its components.
     *
     * @param  array<string, mixed>  $data
     */
    public function addPosition(Bid $bid, array $data): BidPosition
    {
        return DB::transaction(fn (): BidPosition => $this->addPositionLocked($this->lock($bid), $data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function addPositionLocked(Bid $bid, array $data): BidPosition
    {
        $this->assertDraft($bid);
        $item = isset($data['price_item_id']) ? PriceItem::query()->findOrFail($data['price_item_id']) : null;
        $quantity = (string) $data['quantity'];

        $components = [
            'labour_minutes' => (string) ($data['labour_minutes'] ?? $item?->labour_minutes ?? '0'),
            'material_cost' => (string) ($data['material_cost'] ?? $item?->material_cost ?? '0'),
            'machine_cost' => (string) ($data['machine_cost'] ?? $item?->machine_cost ?? '0'),
        ];

        return $this->storePosition($bid, [
            'position_code' => $data['position_code'] ?? $item?->position_code,
            'description' => $data['description'] ?? $item?->describe($bid->language),
            'unit' => $data['unit'] ?? $item?->unit,
            'quantity' => $quantity,
            'unit_price' => (string) ($data['unit_price'] ?? $item?->unit_price),
            'price_item_id' => $item?->id,
        ], $components);
    }

    /**
     * Adds a work cycle as one composite position: its unit price and budget are the sums of its steps.
     *
     * @param  array<string, mixed>  $data
     */
    public function addWorkCycle(Bid $bid, array $data): BidPosition
    {
        return DB::transaction(fn (): BidPosition => $this->addWorkCycleLocked($this->lock($bid), $data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function addWorkCycleLocked(Bid $bid, array $data): BidPosition
    {
        $this->assertDraft($bid);
        $cycle = WorkCycle::query()->with('steps')->findOrFail($data['work_cycle_id']);

        if ($cycle->steps->isEmpty()) {
            throw new BusinessRuleException('The work cycle has no steps.');
        }

        $sum = ['unit_price' => '0', 'labour_minutes' => '0', 'material_cost' => '0', 'machine_cost' => '0'];
        $steps = [];

        foreach ($cycle->steps as $step) {
            /** @var WorkCycleStep $step */
            foreach (array_keys($sum) as $key) {
                $sum[$key] = Decimal::add($sum[$key], Decimal::mul((string) $step->{$key}, (string) $step->quantity_factor));
            }

            $descriptions = $step->descriptions;
            $steps[] = [
                'description' => $descriptions[$bid->language] ?? (string) reset($descriptions),
                'quantity_factor' => (string) $step->quantity_factor,
                'unit_price' => (string) $step->unit_price,
            ];
        }

        $position = $this->storePosition($bid, [
            'position_code' => $data['position_code'],
            'description' => $data['description'] ?? ($cycle->descriptions[$bid->language] ?? $cycle->name),
            'unit' => $cycle->unit,
            'quantity' => (string) $data['quantity'],
            'unit_price' => Decimal::round2($sum['unit_price']),
            'work_cycle_id' => $cycle->id,
        ], $sum);

        $position->update(['components' => $steps]);

        return $position;
    }

    /**
     * Removes a position of a draft bid.
     */
    public function removePosition(Bid $bid, BidPosition $position): void
    {
        DB::transaction(function () use ($bid, $position): void {
            $this->assertDraft($this->lock($bid));
            $position->delete();
        });
    }

    /**
     * Records the measured quantity of a position of an accepted bid, until the settlement is approved.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordMeasurement(Bid $bid, array $data, ?string $userId): Measurement
    {
        return DB::transaction(function () use ($bid, $data, $userId): Measurement {
            $locked = $this->lock($bid);
            $this->assertBillable($locked);

            if ($locked->settlement_approved_at !== null) {
                throw new BusinessRuleException('The settlement is approved: no further measurement can be recorded.');
            }

            $measurement = new Measurement;
            $measurement->fill([...$data, 'bid_id' => $locked->id, 'recorded_by' => $userId]);
            $measurement->save();

            return $measurement;
        });
    }

    /**
     * The settlement: measured quantities per position, valued at the bid prices, net of the bid discount.
     *
     * @return array{bid_id: string, approved_at: ?string, positions: list<array<string, string>>, gross: string, discount: string, net: string}
     */
    public function settlement(Bid $bid): array
    {
        $measured = Measurement::query()->where('bid_id', $bid->id)->groupBy('bid_position_id')->selectRaw('bid_position_id, SUM(quantity) as quantity')->pluck('quantity', 'bid_position_id');
        $positions = [];
        $gross = '0';

        foreach ($bid->positions()->get() as $position) {
            $quantity = Decimal::normalize((string) ($measured[$position->id] ?? '0'));
            $amount = Decimal::round2(Decimal::mul($quantity, (string) $position->unit_price));
            $gross = Decimal::add($gross, $amount);
            $positions[] = [
                'bid_position_id' => $position->id,
                'position_code' => $position->position_code,
                'unit' => $position->unit,
                'bid_quantity' => (string) $position->quantity,
                'measured_quantity' => rtrim(rtrim($quantity, '0'), '.') ?: '0',
                'unit_price' => (string) $position->unit_price,
                'amount' => $amount,
            ];
        }

        [$discount, $net] = $this->applyDiscount($bid, $gross);

        return ['bid_id' => $bid->id, 'approved_at' => $bid->settlement_approved_at?->toIso8601String(), 'positions' => $positions, 'gross' => Decimal::round2($gross), 'discount' => $discount, 'net' => $net];
    }

    /**
     * Approves the settlement: it freezes the measurements, and the final account can then be issued.
     */
    public function approveSettlement(Bid $bid): Bid
    {
        return DB::transaction(function () use ($bid): Bid {
            $locked = $this->lock($bid);
            $this->assertBillable($locked);

            if ($locked->settlement_approved_at !== null) {
                throw new BusinessRuleException('The settlement is already approved.');
            }

            if (! Measurement::query()->where('bid_id', $locked->id)->exists()) {
                throw new BusinessRuleException('Nothing is measured yet.');
            }

            $locked->update(['settlement_approved_at' => now()]);

            return $locked;
        });
    }

    private function lock(Bid $bid): Bid
    {
        return Bid::query()->lockForUpdate()->findOrFail($bid->id);
    }

    /**
     * Net total of the bid: positions less the bid discount.
     */
    public function bidNet(Bid $bid): string
    {
        $gross = (string) $bid->positions()->get()->reduce(static fn (string $sum, BidPosition $position): string => Decimal::add($sum, (string) $position->total), '0');

        return $this->applyDiscount($bid, $gross)[1];
    }

    /**
     * Issues a partial billing (a percentage of the bid, or the measured quantities so far) or the final account (the
     * approved settlement less everything invoiced before, which releases the retentions).
     *
     * @param  array<string, mixed>  $data
     */
    public function bill(Bid $bid, array $data): Billing
    {
        return DB::transaction(function () use ($bid, $data): Billing {
            $locked = Bid::query()->lockForUpdate()->findOrFail($bid->id);
            $this->assertBillable($locked);
            $previous = Billing::query()->where('bid_id', $locked->id)->orderBy('sequence')->get();

            if ($previous->contains('kind', 'final')) {
                throw new BusinessRuleException('The final account is already issued.');
            }

            $previousGross = (string) $previous->reduce(static fn (string $sum, Billing $billing): string => Decimal::add($sum, (string) $billing->gross_this), '0');
            $previousInvoiced = (string) $previous->reduce(static fn (string $sum, Billing $billing): string => Decimal::add($sum, (string) $billing->amount_invoiced), '0');
            $kind = (string) $data['kind'];

            if ($kind === 'final') {
                if ($locked->settlement_approved_at === null) {
                    throw new BusinessRuleException('The final account needs an approved settlement.');
                }

                $basis = 'settlement';
                $toDate = $this->settlement($locked)['net'];
                $grossThis = Decimal::round2(Decimal::sub($toDate, $previousGross));
                $retention = '0.00';
                $amount = Decimal::round2(Decimal::sub($toDate, $previousInvoiced));

                if (! Decimal::isPositive($amount)) {
                    throw new BusinessRuleException('Nothing is left to invoice: a credit note is needed when the partial billings exceed the settlement.');
                }
            } else {
                if ($locked->settlement_approved_at !== null) {
                    throw new BusinessRuleException('The settlement is approved: issue the final account.');
                }

                $basis = (string) $data['basis'];
                $toDate = $basis === 'percentage'
                    ? Decimal::round2(Decimal::div(Decimal::mul($this->bidNet($locked), (string) $data['percentage']), '100'))
                    : $this->settlement($locked)['net'];
                $grossThis = Decimal::round2(Decimal::sub($toDate, $previousGross));

                if (! Decimal::isPositive($grossThis)) {
                    throw new BusinessRuleException('The progress to date does not exceed what is already billed.');
                }

                $retention = Decimal::round2(Decimal::div(Decimal::mul($grossThis, (string) $locked->retention_percentage), '100'));
                $amount = Decimal::round2(Decimal::sub($grossThis, $retention));
            }

            $sequence = $previous->count() + 1;
            $label = $kind === 'final' ? 'Final account' : "Partial billing {$sequence}";
            $items = $kind === 'final'
                ? [$this->invoiceLine("{$label}, settlement of bid {$locked->bid_number}", Decimal::round2($toDate), $locked), ...(Decimal::isZero($previousInvoiced) ? [] : [$this->invoiceLine('Less previous partial billings', Decimal::round2(Decimal::neg($previousInvoiced)), $locked)])]
                : [$this->invoiceLine("{$label}, bid {$locked->bid_number}", $grossThis, $locked), ...(Decimal::isZero($retention) ? [] : [$this->invoiceLine('Retention', Decimal::round2(Decimal::neg($retention)), $locked)])];

            $invoice = $this->invoices->createInvoice([
                'contact_id' => $locked->contact_id,
                'invoice_type' => 'standard',
                'date' => $data['date'],
                'currency' => $locked->currency,
                'notes' => "{$label}: {$locked->title}",
                'items' => $items,
            ]);

            $billing = new Billing;
            $billing->fill([
                'bid_id' => $locked->id,
                'kind' => $kind,
                'basis' => $basis,
                'sequence' => $sequence,
                'billing_date' => $data['date'],
                'percentage' => $data['percentage'] ?? null,
                'gross_to_date' => Decimal::round2($toDate),
                'previous_gross' => Decimal::round2($previousGross),
                'gross_this' => $grossThis,
                'retention_amount' => $retention,
                'amount_invoiced' => $amount,
                'invoice_id' => $invoice->id,
                'progress_billing_id' => $this->progressBilling($locked, $sequence, $previous->last()?->billing_date?->toDateString(), (string) $data['date'], $grossThis, $retention, $amount),
            ]);
            $billing->save();

            return $billing;
        });
    }

    /**
     * Budget against actual costs and measured progress, per position.
     *
     * @return array{positions: list<array<string, string>>, unassigned_costs: string, totals: array<string, string>}
     */
    public function budgetControl(Bid $bid): array
    {
        $costs = SiteCost::query()->where('bid_id', $bid->id)->get();
        $measured = Measurement::query()->where('bid_id', $bid->id)->groupBy('bid_position_id')->selectRaw('bid_position_id, SUM(quantity) as quantity')->pluck('quantity', 'bid_position_id');
        $rows = [];
        $totals = ['budget' => '0', 'actual' => '0', 'earned' => '0'];

        foreach ($bid->positions()->get() as $position) {
            $own = $costs->where('bid_position_id', $position->id);
            $actual = [];

            foreach (SiteCost::Types as $type) {
                $actual[$type] = (string) $own->where('cost_type', $type)->reduce(static fn (string $sum, SiteCost $cost): string => Decimal::add($sum, (string) $cost->amount), '0');
            }

            $actualTotal = array_reduce($actual, static fn (string $sum, string $amount): string => Decimal::add($sum, $amount), '0');
            $budget = Decimal::add(Decimal::add((string) $position->budget_labour_amount, (string) $position->budget_material_amount), (string) $position->budget_machine_amount);
            $progress = Decimal::isZero((string) $position->quantity) ? '0' : Decimal::div((string) ($measured[$position->id] ?? '0'), (string) $position->quantity);
            $earned = Decimal::round2(Decimal::mul($budget, $progress));
            $hours = (string) $own->where('cost_type', 'labour')->reduce(static fn (string $sum, SiteCost $cost): string => Decimal::add($sum, (string) $cost->quantity), '0');

            $rows[] = [
                'bid_position_id' => $position->id,
                'position_code' => $position->position_code,
                'budget_labour_hours' => (string) $position->budget_labour_hours,
                'actual_labour_hours' => Decimal::round2($hours),
                'budget_labour' => (string) $position->budget_labour_amount,
                'budget_material' => (string) $position->budget_material_amount,
                'budget_machine' => (string) $position->budget_machine_amount,
                'budget_total' => Decimal::round2($budget),
                'actual_labour' => Decimal::round2($actual['labour']),
                'actual_material' => Decimal::round2($actual['material']),
                'actual_machine' => Decimal::round2($actual['machine']),
                'actual_other' => Decimal::round2($actual['other']),
                'actual_total' => Decimal::round2($actualTotal),
                'progress_percentage' => Decimal::round2(Decimal::mul($progress, '100')),
                'earned_budget' => $earned,
                'variance' => Decimal::round2(Decimal::sub($earned, $actualTotal)),
            ];

            $totals['budget'] = Decimal::add($totals['budget'], $budget);
            $totals['actual'] = Decimal::add($totals['actual'], $actualTotal);
            $totals['earned'] = Decimal::add($totals['earned'], $earned);
        }

        $unassigned = (string) $costs->whereNull('bid_position_id')->reduce(static fn (string $sum, SiteCost $cost): string => Decimal::add($sum, (string) $cost->amount), '0');

        return [
            'positions' => $rows,
            'unassigned_costs' => Decimal::round2($unassigned),
            'totals' => [
                'budget' => Decimal::round2($totals['budget']),
                'actual' => Decimal::round2(Decimal::add($totals['actual'], $unassigned)),
                'earned' => Decimal::round2($totals['earned']),
                'variance' => Decimal::round2(Decimal::sub($totals['earned'], Decimal::add($totals['actual'], $unassigned))),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordCost(Bid $bid, array $data): SiteCost
    {
        $cost = new SiteCost;
        $cost->fill([
            ...$data,
            'bid_id' => $bid->id,
            'amount' => Decimal::round2(Decimal::mul((string) $data['quantity'], (string) $data['unit_cost'])),
        ]);
        $cost->save();

        return $cost;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array{labour_minutes: string, material_cost: string, machine_cost: string}  $components  per unit
     */
    private function storePosition(Bid $bid, array $attributes, array $components): BidPosition
    {
        if (BidPosition::query()->where('bid_id', $bid->id)->where('position_code', $attributes['position_code'])->exists()) {
            throw new BusinessRuleException("Position {$attributes['position_code']} is already in the bid.");
        }

        $quantity = (string) $attributes['quantity'];
        $hours = Decimal::div(Decimal::mul($components['labour_minutes'], $quantity), '60');

        $position = new BidPosition;
        $position->fill([
            ...$attributes,
            'bid_id' => $bid->id,
            'sort_order' => (int) $bid->positions()->max('sort_order') + 1,
            'total' => Decimal::round2(Decimal::mul($quantity, (string) $attributes['unit_price'])),
            'budget_labour_hours' => Decimal::round2($hours),
            'budget_labour_amount' => Decimal::round2(Decimal::mul($hours, (string) $bid->labour_hour_rate)),
            'budget_material_amount' => Decimal::round2(Decimal::mul($components['material_cost'], $quantity)),
            'budget_machine_amount' => Decimal::round2(Decimal::mul($components['machine_cost'], $quantity)),
        ]);
        $position->save();

        return $position;
    }

    /**
     * @return array{0: string, 1: string} discount and net
     */
    private function applyDiscount(Bid $bid, string $gross): array
    {
        $discount = Decimal::round2(Decimal::div(Decimal::mul($gross, (string) $bid->discount_percentage), '100'));

        return [$discount, Decimal::round2(Decimal::sub($gross, $discount))];
    }

    /**
     * @return array{description: string, quantity: int, unit_price: string, tax_rate: string}
     */
    private function invoiceLine(string $description, string $amount, Bid $bid): array
    {
        return ['description' => $description, 'quantity' => 1, 'unit_price' => $amount, 'tax_rate' => (string) $bid->tax_rate];
    }

    /**
     * Mirrors the billing on the progress billings (SAL) of the construction site, when the bid has one.
     */
    private function progressBilling(Bid $bid, int $sequence, ?string $periodStart, string $date, string $gross, string $retention, string $net): ?string
    {
        if ($bid->construction_site_id === null) {
            return null;
        }

        return (string) ProgressBilling::create([
            'construction_site_id' => $bid->construction_site_id,
            'sal_number' => "{$bid->bid_number}-{$sequence}",
            'period_start' => $periodStart ?? $bid->created_at?->toDateString() ?? $date,
            'period_end' => $date,
            'gross_certified_amount' => $gross,
            'retention_amount' => $retention,
            'net_payable_amount' => $net,
            'status' => 'invoiced',
        ])->id;
    }

    private function assertDraft(Bid $bid): void
    {
        if ($bid->status !== 'draft') {
            throw new BusinessRuleException('Only a draft bid can change.');
        }
    }

    private function assertBillable(Bid $bid): void
    {
        if ($bid->status !== 'accepted') {
            throw new BusinessRuleException('Only an accepted bid is measured and billed.');
        }
    }
}
