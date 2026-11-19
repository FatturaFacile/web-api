<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use App\Services\Sales\DocumentNumberingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Models\BlanketAgreement;
use Modules\Invoicing\Models\BlanketAgreementLine;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderItem;

/**
 * Life cycle of the blanket agreements (call-off contracts): draft, activate, suspend, close and renew, and the
 * call-off orders released against them. A call-off consumes the contract balance atomically on locked rows; an
 * agreement whose balance is used up becomes exhausted, and cancelling a call-off gives the undelivered part back.
 *
 * Consumption is measured in quantity per line for a quantity based agreement, and in amount (net of discount) against
 * the committed total for a value based one. The tolerance percent lets a release exceed the balance by that share.
 */
final class BlanketAgreementService
{
    public const string Draft = 'draft';

    public const string Active = 'active';

    public const string Suspended = 'suspended';

    public const string Exhausted = 'exhausted';

    public const string Expired = 'expired';

    public const string Closed = 'closed';

    public const string QuantityBased = 'quantity_based';

    public const string ValueBased = 'value_based';

    public const string SpotPrice = 'spot_price_with_discount';

    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly SalesOrderServiceInterface $orders,
        private readonly SalesOrderAuditTrail $audit,
    ) {}

    /**
     * Creates a draft agreement with its lines.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function create(string $tenantId, array $data, array $lines, ?string $userId = null): BlanketAgreement
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('sales.blanket_agreement_lines_required')]);
        }

        $type = (string) $data['agreement_type'];

        return DB::transaction(function () use ($tenantId, $data, $lines, $type, $userId): BlanketAgreement {
            $validFrom = CarbonImmutable::parse((string) $data['valid_from']);
            $agreement = BlanketAgreement::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'agreement_number' => $this->numbering->next('blanket_agreement', $validFrom->year, $tenantId)['number'],
                'contact_id' => (string) $data['contact_id'],
                'currency_code' => $data['currency_code'] ?? 'EUR',
                'exchange_rate' => $data['exchange_rate'] ?? '1',
                'agreement_type' => $type,
                'valid_from' => $validFrom->toDateString(),
                'valid_to' => (string) $data['valid_to'],
                'status' => self::Draft,
                'total_committed_amount' => '0',
                'total_called_off_amount' => '0',
                'pricing_lock_mode' => $data['pricing_lock_mode'] ?? 'fixed_contract_price',
                'over_release_tolerance_percent' => $data['over_release_tolerance_percent'] ?? '0',
                'payment_terms' => $data['payment_terms'] ?? null,
                'incoterms' => $data['incoterms'] ?? null,
                'notes' => $data['notes'] ?? null,
                'renewed_from_id' => $data['renewed_from_id'] ?? null,
                'created_by' => $this->uuidOrNull($userId),
            ]);

            $this->replaceLines($agreement, $lines, $type, $data['total_committed_amount'] ?? null);

            return $agreement->load('lines');
        });
    }

    /**
     * Updates a draft agreement; an agreement in force is changed through its life cycle only.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>|null  $lines
     */
    public function update(BlanketAgreement $agreement, array $data, ?array $lines, ?string $userId = null): BlanketAgreement
    {
        return DB::transaction(function () use ($agreement, $data, $lines, $userId): BlanketAgreement {
            $locked = $this->lock($agreement);

            if ($locked->status !== self::Draft) {
                throw ValidationException::withMessages(['status' => __('sales.blanket_agreement_not_draft')]);
            }

            $locked->update(array_merge(
                array_intersect_key($data, array_flip(['currency_code', 'exchange_rate', 'valid_from', 'valid_to', 'pricing_lock_mode', 'over_release_tolerance_percent', 'payment_terms', 'incoterms', 'notes'])),
                ['updated_by' => $this->uuidOrNull($userId)]
            ));

            if ($lines !== null) {
                $this->replaceLines($locked, $lines, (string) $locked->agreement_type, $data['total_committed_amount'] ?? null);
            }

            return $locked->load('lines');
        });
    }

    public function activate(BlanketAgreement $agreement, ?string $userId = null): BlanketAgreement
    {
        return $this->transition($agreement, [self::Draft, self::Suspended], self::Active, $userId, ['activated_at' => now()]);
    }

    public function suspend(BlanketAgreement $agreement, ?string $userId = null): BlanketAgreement
    {
        return $this->transition($agreement, [self::Active], self::Suspended, $userId);
    }

    public function close(BlanketAgreement $agreement, ?string $userId = null): BlanketAgreement
    {
        return $this->transition($agreement, [self::Draft, self::Active, self::Suspended, self::Exhausted, self::Expired], self::Closed, $userId, ['closed_at' => now()]);
    }

    /**
     * Renews an agreement: a new draft with the same lines and prices, a fresh balance and a validity period of the
     * same length starting the day after the old one ends (or at the dates given).
     *
     * @param  array<string, mixed>  $data  optional `valid_from`, `valid_to`
     */
    public function renew(BlanketAgreement $agreement, array $data = [], ?string $userId = null): BlanketAgreement
    {
        $agreement->loadMissing('lines');
        $length = (int) $agreement->valid_from->diffInDays($agreement->valid_to, true);
        $validFrom = isset($data['valid_from']) ? CarbonImmutable::parse((string) $data['valid_from']) : CarbonImmutable::parse($agreement->valid_to->toDateString())->addDay();
        $validTo = isset($data['valid_to']) ? CarbonImmutable::parse((string) $data['valid_to']) : $validFrom->addDays($length);

        $lines = $agreement->lines->map(static fn (BlanketAgreementLine $line): array => [
            'product_id' => $line->product_id,
            'description' => $line->description,
            'unit_of_measure' => $line->unit_of_measure,
            'target_quantity' => $line->target_quantity,
            'unit_price' => $line->unit_price,
            'discount_percent' => $line->discount_percent,
            'tax_rate' => $line->tax_rate,
            'target_amount' => $line->target_amount,
            'delivery_schedule' => $line->delivery_schedule,
        ])->all();

        return $this->create((string) $agreement->tenant_id, [
            'contact_id' => $agreement->contact_id,
            'currency_code' => $agreement->currency_code,
            'exchange_rate' => $agreement->exchange_rate,
            'agreement_type' => $agreement->agreement_type,
            'valid_from' => $validFrom->toDateString(),
            'valid_to' => $validTo->toDateString(),
            'pricing_lock_mode' => $agreement->pricing_lock_mode,
            'over_release_tolerance_percent' => $agreement->over_release_tolerance_percent,
            'payment_terms' => $agreement->payment_terms,
            'incoterms' => $agreement->incoterms,
            'notes' => $agreement->notes,
            'renewed_from_id' => (string) $agreement->id,
            'total_committed_amount' => $agreement->agreement_type === self::ValueBased ? $agreement->total_committed_amount : null,
        ], $lines, $userId);
    }

    /**
     * Releases a call-off order against an active agreement inside its validity period. Each release line names the
     * agreement line and the quantity (or, for a value based agreement, the amount) to call off; the order inherits the
     * contract price and discount (unless the agreement uses the spot price), payment terms and currency.
     *
     * @param  array<int, array{blanket_agreement_line_id: string, quantity?: float|int|string, amount?: float|int|string}>  $releaseLines
     * @param  array<string, mixed>  $orderData  optional `requested_delivery_date`, `warehouse_id`, `notes`
     */
    public function callOff(BlanketAgreement $agreement, array $releaseLines, array $orderData = [], ?string $userId = null): SalesOrder
    {
        if ($releaseLines === []) {
            throw ValidationException::withMessages(['lines' => __('sales.blanket_agreement_lines_required')]);
        }

        return DB::transaction(function () use ($agreement, $releaseLines, $orderData, $userId): SalesOrder {
            $locked = $this->lock($agreement);
            $today = CarbonImmutable::now()->startOfDay();

            if ($locked->status !== self::Active) {
                throw ValidationException::withMessages(['status' => __('sales.blanket_agreement_not_active')]);
            }

            if ($today->lessThan($locked->valid_from) || $today->greaterThan($locked->valid_to)) {
                throw ValidationException::withMessages(['status' => __('sales.blanket_agreement_outside_validity')]);
            }

            $tolerance = bcadd('1', bcdiv((string) $locked->over_release_tolerance_percent, '100', 6), 6);
            $lines = BlanketAgreementLine::query()->where('blanket_agreement_id', (string) $locked->id)->lockForUpdate()->get()->keyBy('id');
            $items = [];
            $releasedAmount = '0';

            foreach ($releaseLines as $index => $release) {
                $line = $lines->get((string) $release['blanket_agreement_line_id']);

                if ($line === null) {
                    throw ValidationException::withMessages(["lines.{$index}.blanket_agreement_line_id" => __('sales.blanket_agreement_line_unknown')]);
                }

                $netPrice = $this->netUnitPrice($line);
                $quantity = isset($release['quantity'])
                    ? bcadd((string) $release['quantity'], '0', 4)
                    : ($this->isPositive($netPrice) ? bcdiv((string) ($release['amount'] ?? '0'), $netPrice, 4) : '0.0000');

                if (! $this->isPositive($quantity)) {
                    throw ValidationException::withMessages(["lines.{$index}.quantity" => __('sales.blanket_agreement_quantity_required')]);
                }

                $amount = bcmul($quantity, $netPrice, 4);

                if ($locked->agreement_type === self::QuantityBased) {
                    $limit = bcmul((string) $line->target_quantity, $tolerance, 4);

                    if (bccomp(bcadd((string) $line->called_off_quantity, $quantity, 4), $limit, 4) > 0) {
                        throw ValidationException::withMessages(["lines.{$index}.quantity" => __('sales.blanket_agreement_balance_exceeded', ['line' => (string) $line->description])]);
                    }
                }

                $line->update([
                    'called_off_quantity' => bcadd((string) $line->called_off_quantity, $quantity, 4),
                    'called_off_amount' => bcadd((string) $line->called_off_amount, $amount, 4),
                ]);
                $releasedAmount = bcadd($releasedAmount, $amount, 4);

                $items[] = [
                    'blanket_agreement_line_id' => (string) $line->id,
                    'product_id' => $line->product_id,
                    'description' => $line->description,
                    'quantity' => $quantity,
                    'uom' => $line->unit_of_measure,
                    'tax_rate' => (string) $line->tax_rate,
                    'warehouse_id' => $orderData['warehouse_id'] ?? null,
                    'requested_date' => $orderData['requested_delivery_date'] ?? null,
                ] + ($locked->pricing_lock_mode === self::SpotPrice
                    ? ['discount_percentage' => (string) $line->discount_percent]
                    : ['unit_price' => (string) $line->unit_price, 'discount_percentage' => (string) $line->discount_percent]);
            }

            if ($locked->agreement_type === self::ValueBased) {
                $limit = bcmul((string) $locked->total_committed_amount, $tolerance, 4);

                if (bccomp(bcadd((string) $locked->total_called_off_amount, $releasedAmount, 4), $limit, 4) > 0) {
                    throw ValidationException::withMessages(['lines' => __('sales.blanket_agreement_balance_exceeded', ['line' => (string) $locked->agreement_number])]);
                }
            }

            $locked->update(['total_called_off_amount' => bcadd((string) $locked->total_called_off_amount, $releasedAmount, 4)]);

            $order = $this->orders->createOrder([
                'tenant_id' => (string) $locked->tenant_id,
                'contact_id' => (string) $locked->contact_id,
                'currency' => $locked->currency_code,
                'payment_terms' => $locked->payment_terms,
                'order_type' => 'call_off',
                'blanket_agreement_id' => (string) $locked->id,
                'warehouse_id' => $orderData['warehouse_id'] ?? null,
                'requested_delivery_date' => $orderData['requested_delivery_date'] ?? null,
                'notes' => $orderData['notes'] ?? null,
                'created_by' => $userId,
            ], $items);

            $this->refreshExhaustion($locked);
            $this->audit->record($order, 'call_off_created', ['blanket_agreement_id' => (string) $locked->id, 'released_amount' => $releasedAmount], $userId);

            return $order;
        });
    }

    /**
     * Gives the undelivered quantity of a cancelled call-off order back to its agreement; an exhausted agreement with
     * balance again returns to active. Safe to call for any order: only call-off orders are touched.
     */
    public function restoreBalance(SalesOrder $order): void
    {
        if ($order->order_type !== 'call_off' || $order->blanket_agreement_id === null) {
            return;
        }

        $order->loadMissing('items');

        foreach ($order->items as $item) {
            $open = bcsub(bcsub((string) $item->quantity, (string) $item->quantity_delivered, 4), (string) $item->quantity_cancelled, 4);

            if ($this->isPositive($open)) {
                $this->restoreLineQuantity($order, $item, $open);
            }
        }
    }

    /**
     * Gives a quantity of one call-off line back to the agreement line (a cancelled residual or a cancelled order).
     */
    public function restoreLineQuantity(SalesOrder $order, SalesOrderItem $item, string $quantity): void
    {
        if ($order->order_type !== 'call_off' || $order->blanket_agreement_id === null || $item->blanket_agreement_line_id === null || ! $this->isPositive($quantity)) {
            return;
        }

        $agreement = BlanketAgreement::query()->whereKey($order->blanket_agreement_id)->lockForUpdate()->first();
        $line = BlanketAgreementLine::query()->whereKey($item->blanket_agreement_line_id)->lockForUpdate()->first();

        if ($agreement === null || $line === null) {
            return;
        }

        $amount = bcmul($quantity, $this->netUnitPrice($line), 4);
        $line->update([
            'called_off_quantity' => $this->floorZero(bcsub((string) $line->called_off_quantity, $quantity, 4)),
            'called_off_amount' => $this->floorZero(bcsub((string) $line->called_off_amount, $amount, 4)),
        ]);
        $agreement->update(['total_called_off_amount' => $this->floorZero(bcsub((string) $agreement->total_called_off_amount, $amount, 4))]);

        if ($agreement->status === self::Exhausted && ! $this->isExhausted($agreement->refresh())) {
            $agreement->update(['status' => self::Active]);
        }

        $this->audit->record($order, 'call_off_balance_restored', ['blanket_agreement_id' => (string) $agreement->id, 'line_id' => (string) $line->id, 'quantity' => $quantity, 'amount' => $amount], null, null, null, (string) $item->id);
    }

    /**
     * Marks the agreements past their validity as expired. Returns how many were expired.
     */
    public function expireOverdue(string $tenantId, ?CarbonImmutable $today = null): int
    {
        return BlanketAgreement::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [self::Active, self::Suspended])
            ->whereDate('valid_to', '<', ($today ?? CarbonImmutable::now())->toDateString())
            ->update(['status' => self::Expired]);
    }

    private function refreshExhaustion(BlanketAgreement $agreement): void
    {
        if ($agreement->status === self::Active && $this->isExhausted($agreement)) {
            $agreement->update(['status' => self::Exhausted]);
        }
    }

    private function isExhausted(BlanketAgreement $agreement): bool
    {
        if ($agreement->agreement_type === self::ValueBased) {
            return $this->isPositive((string) $agreement->total_committed_amount)
                && bccomp((string) $agreement->total_called_off_amount, (string) $agreement->total_committed_amount, 4) >= 0;
        }

        $lines = BlanketAgreementLine::query()->where('blanket_agreement_id', (string) $agreement->id)->get();

        return $lines->isNotEmpty() && $lines->every(static fn (BlanketAgreementLine $line): bool => bccomp((string) $line->called_off_quantity, (string) $line->target_quantity, 4) >= 0);
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $extra
     */
    private function transition(BlanketAgreement $agreement, array $from, string $to, ?string $userId, array $extra = []): BlanketAgreement
    {
        return DB::transaction(function () use ($agreement, $from, $to, $userId, $extra): BlanketAgreement {
            $locked = $this->lock($agreement);

            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['status' => __('sales.blanket_agreement_invalid_transition', ['from' => (string) $locked->status, 'to' => $to])]);
            }

            $locked->update(array_merge($extra, ['status' => $to, 'updated_by' => $this->uuidOrNull($userId)]));

            return $locked->load('lines');
        });
    }

    private function lock(BlanketAgreement $agreement): BlanketAgreement
    {
        return BlanketAgreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function replaceLines(BlanketAgreement $agreement, array $lines, string $type, mixed $committedAmount): void
    {
        BlanketAgreementLine::query()->where('blanket_agreement_id', (string) $agreement->id)->forceDelete();
        $committed = '0';

        foreach (array_values($lines) as $index => $line) {
            $quantity = bcadd((string) ($line['target_quantity'] ?? '0'), '0', 4);
            $price = bcadd((string) ($line['unit_price'] ?? '0'), '0', 4);
            $discount = bcadd((string) ($line['discount_percent'] ?? '0'), '0', 4);
            $net = bcmul($price, bcsub('1', bcdiv($discount, '100', 6), 6), 4);
            $amount = isset($line['target_amount']) && $type === self::ValueBased ? bcadd((string) $line['target_amount'], '0', 4) : bcmul($quantity, $net, 4);
            $committed = bcadd($committed, $amount, 4);

            BlanketAgreementLine::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $agreement->tenant_id,
                'blanket_agreement_id' => (string) $agreement->id,
                'line_number' => $index + 1,
                'product_id' => $line['product_id'] ?? null,
                'description' => $line['description'] ?? 'Agreement line',
                'unit_of_measure' => $line['unit_of_measure'] ?? 'EA',
                'target_quantity' => $quantity,
                'unit_price' => $price,
                'discount_percent' => $discount,
                'tax_rate' => $line['tax_rate'] ?? '0',
                'target_amount' => $amount,
                'delivery_schedule' => $line['delivery_schedule'] ?? null,
            ]);
        }

        $agreement->update(['total_committed_amount' => $type === self::ValueBased && $committedAmount !== null ? bcadd((string) $committedAmount, '0', 4) : $committed]);
    }

    private function netUnitPrice(BlanketAgreementLine $line): string
    {
        return bcmul((string) $line->unit_price, bcsub('1', bcdiv((string) $line->discount_percent, '100', 6), 6), 4);
    }

    private function isPositive(string $value): bool
    {
        return bccomp($value, '0', 4) > 0;
    }

    private function floorZero(string $value): string
    {
        return bccomp($value, '0', 4) < 0 ? '0.0000' : $value;
    }

    private function uuidOrNull(?string $value): ?string
    {
        return $value !== null && Str::isUuid($value) ? $value : null;
    }
}
