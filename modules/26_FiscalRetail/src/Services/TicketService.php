<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Services;

use App\Actions\Retail\RegisterPosSale;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\Product;
use App\Services\Sales\DocumentTotalsCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\FiscalRetail\Models\RetailPromotion;
use Modules\FiscalRetail\Models\RetailSalePayment;
use Modules\FiscalRetail\Models\RetailSalePromotion;

/**
 * Sales tickets of the till: lines priced from the product master data, the promotions of the company applied as
 * negative discount lines (one per promotion and VAT rate, so the VAT stays right), and the total paid with as many
 * methods as the customer wants. The sale itself is registered by the existing POS action, so stock, numbering, loyalty
 * and the session total behave as for every other POS sale.
 */
final class TicketService
{
    public function __construct(
        private readonly RegisterPosSale $register,
        private readonly DocumentTotalsCalculator $totals,
        private readonly PromotionEngine $promotions,
    ) {}

    /**
     * Lines with their discounts and totals, without registering anything.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{items: list<array<string, mixed>>, applied: list<array<string, mixed>>, discount_total: string, total_net: string, total_tax: string, total_gross: string}
     */
    public function quote(array $items): array
    {
        $lines = $this->priced($items);
        $evaluation = $this->promotions->evaluate(
            array_map(static fn (array $line): array => [
                'product_id' => $line['product_id'] ?? null,
                'quantity' => (string) $line['quantity'],
                'unit_price' => (string) $line['unit_price'],
                'tax_rate' => (string) $line['tax_rate'],
            ], $lines),
            now(),
            RetailPromotion::query()->where('is_active', true)->get(),
        );

        foreach ($evaluation['applied'] as $applied) {
            foreach ($applied['by_rate'] as $rate => $amount) {
                $lines[] = [
                    'product_id' => null,
                    'description' => $applied['name'],
                    'quantity' => '1',
                    'unit_price' => '-'.$amount,
                    'tax_rate' => (string) $rate,
                ];
            }
        }

        $computed = $this->totals->calculateDecimal($lines);

        return [
            'items' => $lines,
            'applied' => $evaluation['applied'],
            'discount_total' => $evaluation['discount_total'],
            'total_net' => $computed['total_net'],
            'total_tax' => $computed['total_tax'],
            'total_gross' => $computed['total_gross'],
        ];
    }

    /**
     * @param  array{items: list<array<string, mixed>>, payments?: list<array{method: string, amount: numeric-string|float|int, reference?: string|null}>, contact_id?: string|null}  $data
     * @param  string  $credit  value of returned goods that pays part of the ticket (exchange)
     */
    public function create(PosSession $session, array $data, string $credit = '0.00'): PosSale
    {
        return DB::transaction(function () use ($session, $data, $credit): PosSale {
            $quote = $this->quote($data['items']);
            $payments = $this->settle($quote['total_gross'], $data['payments'] ?? [], $credit);
            $cash = $this->sum($payments, static fn (string $method): bool => $method === 'cash');
            $nonCash = bcsub($this->sum($payments, static fn (): bool => true), $cash, 2);

            $sale = $this->register->execute($session, [
                'items' => $quote['items'],
                'contact_id' => $data['contact_id'] ?? null,
                'payment_method' => bccomp($nonCash, '0', 2) === 0 ? 'cash' : (bccomp($cash, '0', 2) === 0 ? 'card' : 'mixed'),
                'cash_amount' => (float) $cash,
            ]);

            foreach ($payments as $payment) {
                RetailSalePayment::create(['tenant_id' => $sale->tenant_id, 'pos_sale_id' => $sale->id] + $payment);
            }

            foreach ($quote['applied'] as $applied) {
                RetailSalePromotion::create([
                    'tenant_id' => $sale->tenant_id,
                    'pos_sale_id' => $sale->id,
                    'promotion_id' => $applied['promotion_id'],
                    'code' => $applied['code'],
                    'name' => $applied['name'],
                    'discount_net' => $applied['discount_net'],
                ]);
            }

            return $sale;
        });
    }

    /**
     * Checks the payments cover the total and turns what was tendered into what was paid: the change is taken off the
     * cash, which is the only method that may exceed the amount due.
     *
     * @param  list<array{method: string, amount: numeric-string|float|int, reference?: string|null}>  $payments
     * @return list<array{method: string, amount: string, reference: string|null}>
     */
    private function settle(string $gross, array $payments, string $credit): array
    {
        $rows = [];

        if (bccomp($credit, '0', 2) > 0) {
            $rows[] = ['method' => RetailSalePayment::ReturnCredit, 'amount' => $credit, 'reference' => null];
        }

        foreach ($payments as $payment) {
            $rows[] = ['method' => $payment['method'], 'amount' => bcadd((string) $payment['amount'], '0', 2), 'reference' => $payment['reference'] ?? null];
        }

        $nonCash = $this->sum($rows, static fn (string $method): bool => $method !== 'cash');
        $cashTendered = $this->sum($rows, static fn (string $method): bool => $method === 'cash');

        if (bccomp($nonCash, $gross, 2) > 0) {
            throw ValidationException::withMessages(['payments' => __('pos.ticket_payment_excess')]);
        }

        $due = bcsub($gross, $nonCash, 2);

        if (bccomp($cashTendered, $due, 2) < 0) {
            throw ValidationException::withMessages(['payments' => __('pos.ticket_payment_short')]);
        }

        if (bccomp($cashTendered, '0', 2) > 0 && bccomp($due, '0', 2) === 0) {
            throw ValidationException::withMessages(['payments' => __('pos.ticket_payment_excess')]);
        }

        $change = bcsub($cashTendered, $due, 2);

        foreach (array_reverse(array_keys($rows)) as $index) {
            if ($rows[$index]['method'] !== 'cash' || bccomp($change, '0', 2) === 0) {
                continue;
            }

            $taken = bccomp($change, $rows[$index]['amount'], 2) > 0 ? $rows[$index]['amount'] : $change;
            $rows[$index]['amount'] = bcsub($rows[$index]['amount'], $taken, 2);
            $change = bcsub($change, $taken, 2);
        }

        return array_values(array_filter($rows, static fn (array $row): bool => bccomp($row['amount'], '0', 2) > 0));
    }

    /**
     * @param  list<array{method: string, amount: string}>  $rows
     * @param  callable(string): bool  $matches
     */
    private function sum(array $rows, callable $matches): string
    {
        return array_reduce($rows, static fn (string $sum, array $row): string => $matches($row['method']) ? bcadd($sum, $row['amount'], 2) : $sum, '0.00');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function priced(array $items): array
    {
        $products = Product::query()->whereIn('id', collect($items)->pluck('product_id')->filter())->get()->keyBy('id');

        return array_map(function (array $item) use ($products): array {
            $product = isset($item['product_id']) ? $products->get($item['product_id']) : null;

            return [
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'] ?? $product?->name,
                'quantity' => (string) $item['quantity'],
                'unit_price' => (string) ($item['unit_price'] ?? $product?->sale_price ?? 0),
                'tax_rate' => (string) ($item['tax_rate'] ?? $product?->tax_rate ?? 0),
            ];
        }, array_values($items));
    }
}
