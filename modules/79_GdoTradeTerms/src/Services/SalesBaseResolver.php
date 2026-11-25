<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\GdoTradeTerms\Domain\Enums\DateBasis;
use Modules\GdoTradeTerms\Domain\Enums\RebateBasis;
use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Models\GdoPromotion;
use Modules\GdoTradeTerms\Models\GdoScale;
use Modules\GdoTradeTerms\Support\Money;

/**
 * Reads the qualifying sales of a contract from the issued invoices of the retailer (and of its member stores): net value and
 * quantity, credit notes and returns subtracted, the invoices that this module's own settlement credit notes are left out so
 * that settling a rebate never lowers its own base. The window is filtered by invoice, order or delivery date; a scope narrows
 * the lines by product, category (with its sub-categories), brand and delivery point.
 *
 * @phpstan-type Scope array{product_ids?: list<string>|null, category_ids?: list<string>|null, brand_ids?: list<string>|null, delivery_point_ids?: list<string>|null}
 */
final class SalesBaseResolver
{
    private const string IssuedStatus = 'posted';

    /**
     * @return list<string> the retailer and, when the contract says so, the contacts that have it as parent
     */
    public function contactIds(GdoContract $contract): array
    {
        $ids = [(string) $contract->contact_id];

        if ($contract->include_child_contacts) {
            $children = DB::table('contacts')->where('tenant_id', $contract->tenant_id)->where('parent_id', $contract->contact_id)->whereNull('deleted_at')->pluck('id');

            foreach ($children as $child) {
                $ids[] = (string) $child;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Net sell-in of the window.
     *
     * @param  array<string, mixed>  $scope
     * @return array{value: string, quantity: string}
     */
    public function sellIn(GdoContract $contract, Carbon $from, Carbon $to, DateBasis $basis, array $scope = []): array
    {
        if ($to->lt($from)) {
            return ['value' => Money::round('0'), 'quantity' => Money::round('0')];
        }

        $query = DB::table('invoice_items as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->leftJoin('products as p', 'p.id', '=', 'ii.product_id')
            ->where('i.tenant_id', $contract->tenant_id)
            ->whereIn('i.contact_id', $this->contactIds($contract))
            ->where('i.status', self::IssuedStatus)
            ->where('i.invoice_type', '!=', 'proforma')
            ->whereNull('i.deleted_at')
            ->whereNull('ii.deleted_at')
            ->whereNotIn('i.id', DB::table('gdt_settlements')->whereNotNull('credit_note_id')->select('credit_note_id'));

        $this->applyDate($query, $basis, $from, $to);
        $this->applyScope($query, $scope, $contract);

        $row = $query->selectRaw(
            "COALESCE(SUM(CASE WHEN i.invoice_type = 'credit_note' THEN -ii.line_total ELSE ii.line_total END), 0) AS total_value, COALESCE(SUM(CASE WHEN i.invoice_type = 'credit_note' THEN -ii.quantity ELSE ii.quantity END), 0) AS total_quantity",
        )->first();

        return ['value' => Money::from($row->total_value ?? 0), 'quantity' => Money::from($row->total_quantity ?? 0)];
    }

    /**
     * Sell-out reported by the retailer for periods that start in the window.
     *
     * @param  array<string, mixed>  $scope
     * @return array{value: string, quantity: string}
     */
    public function sellOut(GdoContract $contract, Carbon $from, Carbon $to, array $scope = []): array
    {
        if ($to->lt($from)) {
            return ['value' => Money::round('0'), 'quantity' => Money::round('0')];
        }

        $query = DB::table('gdt_sell_out_reports as so')
            ->leftJoin('products as p', 'p.id', '=', 'so.product_id')
            ->where('so.tenant_id', $contract->tenant_id)
            ->where('so.contract_id', $contract->id)
            ->whereNull('so.deleted_at')
            ->whereDate('so.period_start', '>=', $from->toDateString())
            ->whereDate('so.period_start', '<=', $to->toDateString());

        $this->applyScope($query, $scope, $contract, 'so.delivery_point_id');
        $row = $query->selectRaw('COALESCE(SUM(so.amount), 0) AS total_value, COALESCE(SUM(so.quantity), 0) AS total_quantity')->first();

        return ['value' => Money::from($row->total_value ?? 0), 'quantity' => Money::from($row->total_quantity ?? 0)];
    }

    /**
     * Volume of a rebate scale up to a date, in its basis (turnover or quantity), with the sell-in it comes from.
     *
     * @return array{volume: string, value: string, quantity: string}
     */
    public function scaleVolume(GdoScale $scale, GdoContract $contract, Carbon $asOf, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $start = $from ?? $scale->windowStart($contract);
        $end = $to ?? Carbon::parse(min($asOf->toDateString(), $scale->windowEnd($contract)->toDateString()));
        $sales = $this->sellIn($contract, $start, $end, DateBasis::Invoice, $this->scopeOf($scale));

        return ['volume' => $scale->basis === RebateBasis::Quantity ? $sales['quantity'] : $sales['value']] + $sales;
    }

    /**
     * The baseline of an incremental scale: the agreed figure, or what the same scope sold in the previous year's window.
     */
    public function baseline(GdoScale $scale, GdoContract $contract): string
    {
        if ($scale->baseline_source->value !== 'prior_period') {
            return Money::round((string) $scale->baseline_value);
        }

        $sales = $this->sellIn($contract, $scale->windowStart($contract)->copy()->subYear(), $scale->windowEnd($contract)->copy()->subYear(), DateBasis::Invoice, $this->scopeOf($scale));

        return $scale->basis === RebateBasis::Quantity ? $sales['quantity'] : $sales['value'];
    }

    /**
     * Sell-in of a promotion window up to a date.
     *
     * @return array{value: string, quantity: string}
     */
    public function promotionSellIn(GdoPromotion $promotion, GdoContract $contract, Carbon $asOf): array
    {
        $end = Carbon::parse(min($asOf->toDateString(), $promotion->ends_on->toDateString()));

        return $this->sellIn($contract, $promotion->starts_on, $end, $promotion->date_basis, $this->scopeOf($promotion));
    }

    /**
     * @return array{value: string, quantity: string}
     */
    public function promotionSellOut(GdoPromotion $promotion, GdoContract $contract, Carbon $asOf): array
    {
        $end = Carbon::parse(min($asOf->toDateString(), $promotion->ends_on->toDateString()));

        return $this->sellOut($contract, $promotion->starts_on, $end, $this->scopeOf($promotion));
    }

    /**
     * @return array<string, mixed>
     */
    public function scopeOf(GdoScale|GdoPromotion $source): array
    {
        return ['product_ids' => $source->product_ids, 'category_ids' => $source->category_ids, 'brand_ids' => $source->brand_ids, 'delivery_point_ids' => $source->delivery_point_ids];
    }

    /**
     * Whether a product falls in the product, category (with sub-categories) and brand scope. Delivery points are a property of
     * the invoice, not of the product, and are not checked here.
     *
     * @param  array<string, mixed>  $scope
     */
    public function matchesScope(array $scope, string $productId, ?string $categoryId, ?string $brandId, GdoContract $contract): bool
    {
        $products = $this->ids($scope['product_ids'] ?? null);
        $categories = $this->ids($scope['category_ids'] ?? null);
        $brands = $this->ids($scope['brand_ids'] ?? null);

        return ($products === [] || in_array($productId, $products, true))
            && ($categories === [] || ($categoryId !== null && in_array($categoryId, $this->withDescendants($contract, $categories), true)))
            && ($brands === [] || ($brandId !== null && in_array($brandId, $brands, true)));
    }

    private function applyDate(Builder $query, DateBasis $basis, Carbon $from, Carbon $to): void
    {
        $column = match ($basis) {
            DateBasis::Order => 'COALESCE(so_o.date, i.date)',
            DateBasis::Delivery => 'COALESCE(dn.date, i.date)',
            DateBasis::Invoice => 'i.date',
        };

        if ($basis === DateBasis::Order) {
            $query->leftJoin('sales_orders as so_o', 'so_o.id', '=', 'i.sales_order_id');
        } elseif ($basis === DateBasis::Delivery) {
            $query->leftJoin('delivery_notes as dn', 'dn.id', '=', 'i.delivery_note_id');
        }

        $query->whereRaw("{$column} >= ?", [$from->toDateString()])->whereRaw("{$column} <= ?", [$to->toDateString()]);
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function applyScope(Builder $query, array $scope, GdoContract $contract, ?string $pointColumn = null): void
    {
        $products = $this->ids($scope['product_ids'] ?? null);
        $categories = $this->ids($scope['category_ids'] ?? null);
        $brands = $this->ids($scope['brand_ids'] ?? null);
        $points = $this->ids($scope['delivery_point_ids'] ?? null);

        if ($products !== []) {
            $query->whereIn('p.id', $products);
        }

        if ($categories !== []) {
            $query->whereIn('p.category_id', $this->withDescendants($contract, $categories));
        }

        if ($brands !== []) {
            $query->whereIn('p.brand_id', $brands);
        }

        if ($points !== []) {
            if ($pointColumn !== null) {
                $query->whereIn($pointColumn, $points);
            } else {
                $query->whereIn('i.id', DB::table('gdt_invoice_delivery_points')->where('tenant_id', $contract->tenant_id)->whereNull('deleted_at')->whereIn('delivery_point_id', $points)->select('invoice_id'));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function ids(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter(array_map('strval', $value), static fn (string $id): bool => $id !== '')) : [];
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function withDescendants(GdoContract $contract, array $ids): array
    {
        $all = $ids;
        $frontier = $ids;

        for ($depth = 0; $depth < 6 && $frontier !== []; $depth++) {
            $children = DB::table('product_categories')->where('tenant_id', $contract->tenant_id)->whereNull('deleted_at')->whereIn('parent_id', $frontier)->pluck('id')->map(static fn ($id): string => (string) $id)->all();
            $frontier = array_values(array_diff($children, $all));
            $all = array_merge($all, $frontier);
        }

        return array_values(array_unique($all));
    }
}
