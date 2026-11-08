<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use App\Enums\DeliveryNoteStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Tenant;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Sales\DocumentTotalsCalculator;
use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\BillingSchedule;
use Modules\Invoicing\Models\BillingScheduleItem;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderItem;
use Modules\SDI\Enums\TipoDocumento;

/**
 * Billing of sales orders beyond "one order, one invoice": down-payment invoices, deferred invoices collecting several
 * delivery notes (one per customer, also as a batch run), and billing plans (milestone billing) of an order.
 *
 * Every document is created and issued by the invoicing service, so numbering, VAT, fiscal rules, ledger posting and
 * events are the ones of any other invoice. The source documents are locked for the whole transaction, so two
 * concurrent requests cannot bill the same order, plan item or delivery note twice.
 */
final class OrderBillingService
{
    public const string PLAN_ACTIVE = 'active';

    public const string PLAN_COMPLETED = 'completed';

    public const string PLAN_CANCELLED = 'cancelled';

    public const string ITEM_PENDING = 'pending';

    public const string ITEM_INVOICED = 'invoiced';

    public const string ITEM_CANCELLED = 'cancelled';

    public function __construct(
        private readonly InvoicingServiceInterface $invoicing,
        private readonly SalesBillingLines $lines,
        private readonly DocumentNumberingService $numbering,
        private readonly TenantClock $clock,
    ) {}

    /**
     * Issues a down-payment invoice (TD02) for a share of the order: a percentage of its net total, or an amount. The
     * amount is split over the VAT rates of the order in proportion to their net, so every rate keeps its tax. The down
     * payments of an order never exceed its net total; the final invoice of the order deducts them.
     *
     * @param  array<string, mixed>  $overrides  only the keys in InvoicingService::SOURCE_OVERRIDE_FIELDS are used
     *
     * @throws ValidationException when the order cannot take a down payment or the amount exceeds what is left
     */
    public function issueDownPayment(SalesOrder $order, ?string $percentage, ?string $amount, array $overrides, string $userId): SalesInvoice
    {
        return DB::transaction(function () use ($order, $percentage, $amount, $overrides, $userId): SalesInvoice {
            $locked = $this->lockOrder($order);
            $this->assertBillable($locked);

            if ($locked->billing_status === SalesBillingLines::STATUS_BILLED) {
                throw ValidationException::withMessages(['sales_order' => __('sales.order_already_invoiced')]);
            }

            $bases = $this->netByRate($locked);
            $orderNet = array_reduce($bases, static fn (string $sum, string $base): string => Decimal::add($sum, $base), '0');

            if (! Decimal::isPositive($orderNet)) {
                throw ValidationException::withMessages(['sales_order' => __('sales.nothing_to_invoice')]);
            }

            $requested = $amount !== null ? Decimal::round2($amount) : Decimal::round2(Decimal::div(Decimal::mul($orderNet, (string) $percentage), '100'));
            $alreadyRequested = SalesInvoice::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $locked->tenant_id)
                ->where('sales_order_id', $locked->id)
                ->where('is_deposit', true)
                ->pluck('total_net')
                ->reduce(static fn (string $sum, mixed $net): string => Decimal::add($sum, DocumentTotalsCalculator::decimal($net)), '0');

            if (! Decimal::isPositive($requested) || Decimal::compare(Decimal::add($alreadyRequested, $requested), $orderNet) > 0) {
                throw ValidationException::withMessages([$amount !== null ? 'amount' : 'percentage' => __('sales.deposit_exceeds_order')]);
            }

            $label = $percentage !== null ? rtrim(rtrim(Decimal::round2($percentage), '0'), '.') : null;
            $items = [];

            foreach ($this->split($requested, $bases, $orderNet) as $rate => $share) {
                $items[] = [
                    'description' => $label !== null
                        ? __('sales.deposit_line', ['number' => $locked->order_number, 'percentage' => $label])
                        : __('sales.deposit_line_amount', ['number' => $locked->order_number]),
                    'quantity' => '1',
                    'unit_price' => $share,
                    'tax_rate' => (string) $rate,
                    'discount_percentage' => '0',
                ];
            }

            return $this->invoicing->issueInvoice([
                ...$this->sourceOverrides($overrides),
                'tenant_id' => (string) $locked->tenant_id,
                'contact_id' => (string) $locked->contact_id,
                'sales_order_id' => (string) $locked->id,
                'currency' => $locked->currency ?? null,
                'is_deposit' => true,
                'skip_charge_lines' => true,
                'created_by' => $userId,
            ], $items, $userId);
        });
    }

    /**
     * One deferred invoice (fattura differita, TD24 for an Italian company) for several delivery notes of the same
     * customer that were dispatched and not billed yet. Each line keeps its delivery note line, so the order lines are
     * billed and the XML lists the transport documents (DatiDDT).
     *
     * @param  list<string>  $deliveryNoteIds
     * @param  array<string, mixed>  $overrides  only the keys in InvoicingService::SOURCE_OVERRIDE_FIELDS are used
     *
     * @throws ValidationException when the notes are of different customers, not dispatched or already billed
     */
    public function issueDeferredInvoice(array $deliveryNoteIds, array $overrides, string $userId): SalesInvoice
    {
        return DB::transaction(function () use ($deliveryNoteIds, $overrides, $userId): SalesInvoice {
            $ids = array_values(array_unique(array_map('strval', $deliveryNoteIds)));
            sort($ids);

            $notes = SalesDeliveryNote::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

            if ($notes->count() !== count($ids)) {
                throw ValidationException::withMessages(['delivery_note_ids' => __('sales.deferred_notes_not_found')]);
            }

            if ($notes->pluck('contact_id')->unique()->count() > 1) {
                throw ValidationException::withMessages(['delivery_note_ids' => __('sales.deferred_notes_other_customer')]);
            }

            $items = [];

            foreach ($notes as $note) {
                if ($note->status !== DeliveryNoteStatus::Issued->value) {
                    throw ValidationException::withMessages(['delivery_note_ids' => __('sales.deferred_note_not_issued', ['number' => $note->ddt_number])]);
                }

                if ($this->lines->deliveryNoteBilled($note)) {
                    throw ValidationException::withMessages(['delivery_note_ids' => __('sales.delivery_note_already_invoiced')]);
                }

                array_push($items, ...$this->lines->deliveryNoteLines($note));
            }

            if ($items === []) {
                throw ValidationException::withMessages(['delivery_note_ids' => __('sales.nothing_to_invoice')]);
            }

            $first = $notes->first();
            $orderIds = $notes->pluck('sales_order_id')->filter()->unique();
            $currency = $orderIds->isEmpty() ? null : SalesOrder::withoutGlobalScope(TenantScope::class)->whereKey($orderIds->all())->pluck('currency')->filter()->unique();

            if ($currency !== null && $currency->count() > 1) {
                throw ValidationException::withMessages(['delivery_note_ids' => __('sales.deferred_notes_other_currency')]);
            }

            $tenant = Tenant::query()->find($first->tenant_id);
            $isItalian = strtoupper((string) $tenant?->country) === 'IT';

            $invoice = $this->invoicing->issueInvoice([
                ...$this->sourceOverrides($overrides),
                'tenant_id' => (string) $first->tenant_id,
                'contact_id' => (string) $first->contact_id,
                'sales_order_id' => $orderIds->count() === 1 ? (string) $orderIds->first() : null,
                'delivery_note_id' => $notes->count() === 1 ? (string) $first->id : null,
                'currency' => $currency?->first(),
                'document_type' => $isItalian ? TipoDocumento::Td24->value : null,
                'notes' => $overrides['notes'] ?? __('sales.deferred_invoice_notes', ['numbers' => $notes->pluck('ddt_number')->implode(', ')]),
                'created_by' => $userId,
            ], $items, $userId);

            foreach ($notes as $note) {
                DeliveryNoteStatus::assertTransition($note->status, DeliveryNoteStatus::Invoiced);
                SalesDeliveryNote::withoutGlobalScope(TenantScope::class)->whereKey($note->id)->update(['status' => DeliveryNoteStatus::Invoiced->value]);
            }

            return $invoice;
        });
    }

    /**
     * The deferred billing run: one deferred invoice per customer (and currency) for every delivery note dispatched on
     * or before `$upToDate` and not billed yet. Each invoice is issued in its own transaction, so a refused customer
     * (closed period, missing master data) does not stop the others; its error is returned with its notes.
     *
     * @return array{invoices: list<SalesInvoice>, errors: list<array{contact_id: string, delivery_note_ids: list<string>, message: string}>}
     */
    public function runDeferredInvoicing(string $tenantId, string $upToDate, ?string $contactId, array $overrides, string $userId): array
    {
        $notes = SalesDeliveryNote::query()
            ->where('tenant_id', $tenantId)
            ->where('status', DeliveryNoteStatus::Issued->value)
            ->whereDate('date', '<=', $upToDate)
            ->when($contactId !== null, static fn ($query) => $query->where('contact_id', $contactId))
            ->orderBy('date')
            ->get()
            ->reject(fn (SalesDeliveryNote $note): bool => $this->lines->deliveryNoteBilled($note));

        $orderCurrencies = SalesOrder::withoutGlobalScope(TenantScope::class)->whereKey($notes->pluck('sales_order_id')->filter()->unique()->all())->pluck('currency', 'id');
        $groups = $notes->groupBy(static fn (SalesDeliveryNote $note): string => $note->contact_id.'|'.($note->sales_order_id !== null ? (string) ($orderCurrencies[$note->sales_order_id] ?? '') : ''));
        $result = ['invoices' => [], 'errors' => []];

        foreach ($groups as $group) {
            $ids = $group->pluck('id')->map(static fn (mixed $id): string => (string) $id)->values()->all();

            try {
                $result['invoices'][] = $this->issueDeferredInvoice($ids, $overrides, $userId);
            } catch (ValidationException $e) {
                $result['errors'][] = ['contact_id' => (string) $group->first()->contact_id, 'delivery_note_ids' => $ids, 'message' => collect($e->errors())->flatten()->first() ?? $e->getMessage()];
            }
        }

        return $result;
    }

    /**
     * A billing plan (milestone billing) of an order: each item is a share of the order net total (a percentage or an
     * amount) billed on its own invoice on or after its target date. An order billed by a plan is not billed by
     * quantities, so the plan is refused once part of the order is invoiced, and the order cannot have two active plans.
     *
     * @param  list<array{name: string, percentage?: string|int|float|null, amount?: string|int|float|null, target_date: string, milestone_id?: string|null}>  $items
     *
     * @throws ValidationException
     */
    public function createPlan(SalesOrder $order, array $items, string $userId): BillingSchedule
    {
        return DB::transaction(function () use ($order, $items): BillingSchedule {
            $locked = $this->lockOrder($order);
            $this->assertBillable($locked);

            if ($locked->billing_status !== SalesBillingLines::STATUS_UNBILLED) {
                throw ValidationException::withMessages(['sales_order_id' => __('sales.billing_plan_order_already_billed')]);
            }

            $hasPlan = BillingSchedule::query()->where('sales_order_id', $locked->id)->where('status', self::PLAN_ACTIVE)->exists();

            if ($hasPlan) {
                throw ValidationException::withMessages(['sales_order_id' => __('sales.billing_plan_exists')]);
            }

            $orderNet = array_reduce($this->netByRate($locked), static fn (string $sum, string $base): string => Decimal::add($sum, $base), '0');
            $amounts = [];
            $total = '0';

            foreach ($items as $index => $item) {
                $value = isset($item['amount'])
                    ? Decimal::round2(DocumentTotalsCalculator::decimal($item['amount']))
                    : Decimal::round2(Decimal::div(Decimal::mul($orderNet, DocumentTotalsCalculator::decimal($item['percentage'] ?? '0')), '100'));

                if (! Decimal::isPositive($value)) {
                    throw ValidationException::withMessages(["items.{$index}.amount" => __('sales.billing_plan_item_positive')]);
                }

                $amounts[$index] = $value;
                $total = Decimal::add($total, $value);
            }

            if (Decimal::compare($total, $orderNet) > 0) {
                throw ValidationException::withMessages(['items' => __('sales.billing_plan_exceeds_order')]);
            }

            $tenantId = (string) $locked->tenant_id;
            $number = $this->numbering->next('billing_plan', $this->today($tenantId)->year, $tenantId);
            $dates = array_map(static fn (array $item): string => CarbonImmutable::parse($item['target_date'])->toDateString(), $items);
            sort($dates);

            $plan = BillingSchedule::query()->create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'customer_id' => (string) $locked->contact_id,
                'sales_order_id' => (string) $locked->id,
                'schedule_number' => $number['number'],
                'schedule_type' => 'milestone',
                'total_amount' => Decimal::round2($total),
                'billed_amount' => '0.00',
                'remaining_amount' => Decimal::round2($total),
                'currency' => $locked->currency ?? 'EUR',
                'status' => self::PLAN_ACTIVE,
                'start_date' => $dates[0],
                'end_date' => $dates[count($dates) - 1],
                'next_billing_date' => $dates[0],
            ]);

            foreach ($items as $index => $item) {
                BillingScheduleItem::query()->create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'billing_schedule_id' => (string) $plan->id,
                    'milestone_name' => (string) $item['name'],
                    'milestone_id' => $item['milestone_id'] ?? null,
                    'percentage' => isset($item['percentage']) ? Decimal::round2(DocumentTotalsCalculator::decimal($item['percentage'])) : null,
                    'amount' => $amounts[$index],
                    'target_date' => CarbonImmutable::parse($item['target_date'])->toDateString(),
                    'status' => self::ITEM_PENDING,
                ]);
            }

            return $plan->load('items');
        });
    }

    /**
     * Issues the invoice of one item of a billing plan. Its amount is split over the VAT rates of the order in proportion
     * to their net. The plan keeps its billed and remaining amounts, and the order its billing status.
     *
     * @param  array<string, mixed>  $overrides  only the keys in InvoicingService::SOURCE_OVERRIDE_FIELDS are used
     *
     * @throws ValidationException when the item is not pending or the plan is not active
     */
    public function invoicePlanItem(BillingSchedule $plan, string $itemId, array $overrides, string $userId): SalesInvoice
    {
        return DB::transaction(function () use ($plan, $itemId, $overrides, $userId): SalesInvoice {
            $lockedPlan = BillingSchedule::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $item = BillingScheduleItem::query()->where('billing_schedule_id', $lockedPlan->id)->whereKey($itemId)->lockForUpdate()->firstOrFail();

            if ($lockedPlan->status !== self::PLAN_ACTIVE || $item->status !== self::ITEM_PENDING) {
                throw ValidationException::withMessages(['item' => __('sales.billing_plan_item_not_pending')]);
            }

            $order = $this->lockOrder(SalesOrder::withoutGlobalScope(TenantScope::class)->findOrFail($lockedPlan->sales_order_id));
            $this->assertBillable($order);
            $bases = $this->netByRate($order);
            $orderNet = array_reduce($bases, static fn (string $sum, string $base): string => Decimal::add($sum, $base), '0');
            $lines = [];

            foreach ($this->split(DocumentTotalsCalculator::decimal($item->amount), $bases, $orderNet) as $rate => $share) {
                $lines[] = [
                    'description' => __('sales.billing_plan_line', ['milestone' => $item->milestone_name, 'number' => $order->order_number]),
                    'quantity' => '1',
                    'unit_price' => $share,
                    'tax_rate' => (string) $rate,
                    'discount_percentage' => '0',
                ];
            }

            $invoice = $this->invoicing->issueInvoice([
                ...$this->sourceOverrides($overrides),
                'tenant_id' => (string) $lockedPlan->tenant_id,
                'contact_id' => (string) $lockedPlan->customer_id,
                'sales_order_id' => (string) $order->id,
                'currency' => $order->currency ?? null,
                'skip_charge_lines' => true,
                'created_by' => $userId,
            ], $lines, $userId);

            $item->update(['status' => self::ITEM_INVOICED, 'invoice_id' => (string) $invoice->id, 'invoiced_at' => now()]);
            $this->refreshPlan($lockedPlan, $order);

            return $invoice;
        });
    }

    /**
     * Issues every pending item of the active plans of the tenant whose target date is on or before `$upToDate`, each in
     * its own transaction.
     *
     * @return array{invoices: list<SalesInvoice>, errors: list<array{billing_plan_id: string, item_id: string, message: string}>}
     */
    public function invoiceDuePlanItems(string $tenantId, string $upToDate, string $userId): array
    {
        $items = BillingScheduleItem::query()
            ->where('tenant_id', $tenantId)
            ->where('status', self::ITEM_PENDING)
            ->whereDate('target_date', '<=', $upToDate)
            ->whereHas('schedule', static fn ($query) => $query->where('status', self::PLAN_ACTIVE))
            ->orderBy('target_date')
            ->get();
        $result = ['invoices' => [], 'errors' => []];

        foreach ($items as $item) {
            try {
                $result['invoices'][] = $this->invoicePlanItem(BillingSchedule::query()->findOrFail($item->billing_schedule_id), (string) $item->id, [], $userId);
            } catch (ValidationException $e) {
                $result['errors'][] = ['billing_plan_id' => (string) $item->billing_schedule_id, 'item_id' => (string) $item->id, 'message' => collect($e->errors())->flatten()->first() ?? $e->getMessage()];
            }
        }

        return $result;
    }

    /**
     * Cancels a plan: its pending items are cancelled, the invoiced ones stay (they are corrected with credit notes).
     */
    public function cancelPlan(BillingSchedule $plan): BillingSchedule
    {
        return DB::transaction(function () use ($plan): BillingSchedule {
            $locked = BillingSchedule::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== self::PLAN_ACTIVE) {
                throw ValidationException::withMessages(['status' => __('sales.billing_plan_not_active')]);
            }

            BillingScheduleItem::query()->where('billing_schedule_id', $locked->id)->where('status', self::ITEM_PENDING)->update(['status' => self::ITEM_CANCELLED]);
            $locked->update(['status' => self::PLAN_CANCELLED, 'next_billing_date' => null]);

            return $locked->load('items');
        });
    }

    /**
     * Whether an order is billed by an active billing plan, and so not by quantities.
     */
    public function hasActivePlan(string $orderId): bool
    {
        return BillingSchedule::withoutGlobalScope(TenantScope::class)->where('sales_order_id', $orderId)->where('status', self::PLAN_ACTIVE)->exists();
    }

    private function refreshPlan(BillingSchedule $plan, SalesOrder $order): void
    {
        $items = BillingScheduleItem::query()->where('billing_schedule_id', $plan->id)->get();
        $billed = $items->where('status', self::ITEM_INVOICED)->reduce(static fn (string $sum, BillingScheduleItem $item): string => Decimal::add($sum, DocumentTotalsCalculator::decimal($item->amount)), '0');
        $pending = $items->where('status', self::ITEM_PENDING)->sortBy('target_date');

        $plan->update([
            'billed_amount' => Decimal::round2($billed),
            'remaining_amount' => Decimal::round2(Decimal::sub(DocumentTotalsCalculator::decimal($plan->total_amount), $billed)),
            'status' => $pending->isEmpty() ? self::PLAN_COMPLETED : self::PLAN_ACTIVE,
            'next_billing_date' => $pending->first()?->target_date,
        ]);

        SalesOrder::withoutGlobalScope(TenantScope::class)->whereKey($order->id)->update([
            'billing_status' => $pending->isEmpty() ? SalesBillingLines::STATUS_BILLED : SalesBillingLines::STATUS_PARTIALLY_BILLED,
        ]);
    }

    private function lockOrder(SalesOrder $order): SalesOrder
    {
        return SalesOrder::withoutGlobalScope(TenantScope::class)->with('items')->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @throws ValidationException when the order is cancelled or rejected
     */
    private function assertBillable(SalesOrder $order): void
    {
        if (in_array($order->status, [SalesOrderStatus::Cancelled->value, SalesOrderStatus::Rejected->value], true)) {
            throw ValidationException::withMessages(['sales_order' => __('sales.order_cancelled')]);
        }
    }

    /**
     * The net of the order lines per VAT rate.
     *
     * @return array<string, string>
     */
    private function netByRate(SalesOrder $order): array
    {
        $bases = [];

        foreach ($order->items as $item) {
            /** @var SalesOrderItem $item */
            $rate = Decimal::round2(DocumentTotalsCalculator::decimal($item->tax_rate ?? '0'));
            $net = DocumentTotalsCalculator::decimal($item->line_total);
            $bases[$rate] = Decimal::add($bases[$rate] ?? '0', $net);
        }

        return array_filter($bases, static fn (string $base): bool => ! Decimal::isZero($base));
    }

    /**
     * Splits an amount over the VAT rates in proportion to their base; the last rate takes the rounding remainder.
     *
     * @param  array<string, string>  $bases
     * @return array<string, string>
     */
    private function split(string $amount, array $bases, string $total): array
    {
        $shares = [];
        $allocated = '0';
        $rates = array_keys($bases);
        $last = end($rates);

        foreach ($bases as $rate => $base) {
            $share = $rate === $last
                ? Decimal::round2(Decimal::sub($amount, $allocated))
                : Decimal::round2(Decimal::div(Decimal::mul($amount, $base), $total));
            $allocated = Decimal::add($allocated, $share);

            if (! Decimal::isZero($share)) {
                $shares[(string) $rate] = $share;
            }
        }

        return $shares;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sourceOverrides(array $overrides): array
    {
        return array_filter(array_intersect_key($overrides, array_flip(InvoicingService::SOURCE_OVERRIDE_FIELDS)), static fn (mixed $value): bool => $value !== null);
    }

    private function today(string $tenantId): CarbonImmutable
    {
        return $this->clock->today(Tenant::query()->find($tenantId));
    }
}
