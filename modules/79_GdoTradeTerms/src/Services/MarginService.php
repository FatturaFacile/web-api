<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\GdoTradeTerms\Domain\Enums\ContractStatus;
use Modules\GdoTradeTerms\Domain\Enums\PromotionStatus;
use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Models\GdoScale;
use Modules\GdoTradeTerms\Support\Money;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Gross, net and net-net profitability of the sales to GDO customers. Gross margin is the invoiced net value (after the
 * on-invoice discounts) less the product cost; the net-net margin also deducts the estimated off-invoice rebates (the year-end
 * projection of every rebate scale, as a share of the turnover it covers) and the trade contributions earned by the promotions,
 * allocated to the products by their share of the net sales. Everything below the invoice is an estimate until it is settled.
 */
final readonly class MarginService
{
    public function __construct(private SalesBaseResolver $sales, private EarningsService $earnings) {}

    /**
     * @return array<string, mixed>
     */
    public function netNet(string $tenantId, string $from, string $to, ?string $contractId, ?string $contactId, string $groupBy = 'product'): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $contracts = $this->contracts($tenantId, $contractId, $contactId);
        $rows = [];

        foreach ($contracts as $contract) {
            $contract->loadMissing(['scales.tiers', 'promotions']);
            $lines = $this->lines($contract, $start, $end);
            $netTotal = Money::sum(array_column($lines, 'net'));
            $promotions = $this->promotionTotal($contract, $end);
            $rates = $this->scaleRates($contract, $end);

            foreach ($lines as $line) {
                $offInvoice = '0';

                foreach ($rates as $rate) {
                    if ($this->sales->matchesScope($rate['scope'], $line['product_id'], $line['category_id'], $line['brand_id'], $contract)) {
                        $offInvoice = Money::add($offInvoice, Money::percentOf($line['net'], $rate['percent']));
                    }
                }

                $share = Money::isPositive($netTotal) ? Money::round(Decimal::div($line['net'], $netTotal)) : '0';
                $promo = Money::isPositive($netTotal) ? Money::mul($promotions, $share) : '0';
                $key = $groupBy === 'contract' ? $contract->id : $line['product_id'];

                $rows[$key] ??= [
                    'key' => $key,
                    'label' => $groupBy === 'contract' ? $contract->code.' · '.$contract->name : (string) ($line['sku'] ?? '').' · '.(string) ($line['name'] ?? ''),
                    'contract_id' => $groupBy === 'contract' ? $contract->id : null,
                    'product_id' => $groupBy === 'contract' ? null : $line['product_id'],
                    'quantity' => '0', 'gross_sales' => '0', 'net_sales' => '0', 'cost' => '0', 'off_invoice_rebates' => '0', 'promotion_contributions' => '0',
                ];

                $rows[$key]['quantity'] = Money::add($rows[$key]['quantity'], $line['quantity']);
                $rows[$key]['gross_sales'] = Money::add($rows[$key]['gross_sales'], $line['gross']);
                $rows[$key]['net_sales'] = Money::add($rows[$key]['net_sales'], $line['net']);
                $rows[$key]['cost'] = Money::add($rows[$key]['cost'], $line['cost']);
                $rows[$key]['off_invoice_rebates'] = Money::add($rows[$key]['off_invoice_rebates'], $offInvoice);
                $rows[$key]['promotion_contributions'] = Money::add($rows[$key]['promotion_contributions'], $promo);
            }
        }

        $rows = array_values(array_map(fn (array $row): array => $this->finish($row), $rows));
        usort($rows, static fn (array $left, array $right): int => Money::compare($right['net_sales'], $left['net_sales']));

        $totals = $this->finish([
            'key' => 'total', 'label' => 'total', 'contract_id' => null, 'product_id' => null,
            'quantity' => Money::sum(array_column($rows, 'quantity')),
            'gross_sales' => Money::sum(array_column($rows, 'gross_sales')),
            'net_sales' => Money::sum(array_column($rows, 'net_sales')),
            'cost' => Money::sum(array_column($rows, 'cost')),
            'off_invoice_rebates' => Money::sum(array_column($rows, 'off_invoice_rebates')),
            'promotion_contributions' => Money::sum(array_column($rows, 'promotion_contributions')),
        ]);

        return ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'group_by' => $groupBy, 'estimated' => true, 'rows' => $rows, 'totals' => $totals];
    }

    /**
     * @return Collection<int, GdoContract>
     */
    private function contracts(string $tenantId, ?string $contractId, ?string $contactId): Collection
    {
        return GdoContract::query()
            ->where('tenant_id', $tenantId)
            ->when($contractId !== null, fn ($query) => $query->whereKey($contractId))
            ->when($contactId !== null, fn ($query) => $query->where('contact_id', $contactId))
            ->whereIn('status', [ContractStatus::Active->value, ContractStatus::Closed->value, ContractStatus::Suspended->value])
            ->get();
    }

    /**
     * Invoiced lines of the contract per product.
     *
     * @return list<array{product_id: string, sku: string|null, name: string|null, category_id: string|null, brand_id: string|null, quantity: string, gross: string, net: string, cost: string}>
     */
    private function lines(GdoContract $contract, Carbon $from, Carbon $to): array
    {
        $sign = "CASE WHEN i.invoice_type = 'credit_note' THEN -1 ELSE 1 END";

        $rows = DB::table('invoice_items as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->leftJoin('products as p', 'p.id', '=', 'ii.product_id')
            ->where('i.tenant_id', $contract->tenant_id)
            ->whereIn('i.contact_id', $this->sales->contactIds($contract))
            ->where('i.status', 'posted')
            ->where('i.invoice_type', '!=', 'proforma')
            ->whereNull('i.deleted_at')
            ->whereNull('ii.deleted_at')
            ->whereNotNull('ii.product_id')
            ->whereNotIn('i.id', DB::table('gdt_settlements')->whereNotNull('credit_note_id')->select('credit_note_id'))
            ->whereDate('i.date', '>=', $from->toDateString())
            ->whereDate('i.date', '<=', $to->toDateString())
            ->groupBy('ii.product_id', 'p.sku', 'p.name', 'p.category_id', 'p.brand_id')
            ->selectRaw("ii.product_id AS product_id, p.sku AS sku, p.name AS name, p.category_id AS category_id, p.brand_id AS brand_id, SUM({$sign} * ii.quantity) AS qty, SUM({$sign} * ii.quantity * ii.unit_price) AS gross, SUM({$sign} * ii.line_total) AS net, SUM({$sign} * ii.quantity * COALESCE(p.cost_price, 0)) AS cost")
            ->get();

        return $rows->map(fn ($row): array => [
            'product_id' => (string) $row->product_id,
            'sku' => $row->sku,
            'name' => $row->name,
            'category_id' => $row->category_id === null ? null : (string) $row->category_id,
            'brand_id' => $row->brand_id === null ? null : (string) $row->brand_id,
            'quantity' => Money::from($row->qty),
            'gross' => Money::from($row->gross),
            'net' => Money::from($row->net),
            'cost' => Money::from($row->cost),
        ])->all();
    }

    /**
     * Estimated year-end rate of every active scale as a percentage of the turnover it covers.
     *
     * @return list<array{percent: string, scope: array<string, mixed>}>
     */
    private function scaleRates(GdoContract $contract, Carbon $asOf): array
    {
        $rates = [];

        foreach ($contract->scales->where('is_active', true) as $scale) {
            $rates[] = ['percent' => $this->scaleRate($scale, $contract, $asOf), 'scope' => $this->sales->scopeOf($scale)];
        }

        return $rates;
    }

    private function scaleRate(GdoScale $scale, GdoContract $contract, Carbon $asOf): string
    {
        $projection = $this->earnings->scaleProjection($scale, $contract, $asOf);
        $turnover = $this->sales->scaleVolume($scale, $contract, $asOf)['value'];
        $elapsed = (int) $projection['elapsed_days'];

        if ($elapsed === 0 || ! Money::isPositive($turnover)) {
            return Money::round('0');
        }

        $projectedTurnover = Money::round(Decimal::div(Decimal::mul($turnover, (string) $projection['total_days']), (string) $elapsed));

        return Money::isPositive($projectedTurnover) ? Money::round(Decimal::div(Decimal::mul((string) $projection['projected_rebate'], '100'), $projectedTurnover)) : Money::round('0');
    }

    private function promotionTotal(GdoContract $contract, Carbon $asOf): string
    {
        $total = '0';

        foreach ($contract->promotions as $promotion) {
            if (! in_array($promotion->status, [PromotionStatus::Active, PromotionStatus::Ended], true)) {
                continue;
            }

            $total = Money::add($total, $this->earnings->promotionEarned($promotion, $contract, $asOf)['earned']);
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function finish(array $row): array
    {
        $grossMargin = Money::sub($row['net_sales'], $row['cost']);
        $netNet = Money::sub(Money::sub($grossMargin, $row['off_invoice_rebates']), $row['promotion_contributions']);
        $percent = static fn (string $amount, string $base): string => Money::isPositive($base) ? Money::round(Decimal::div(Decimal::mul($amount, '100'), $base), 2) : Money::round('0', 2);

        return [
            ...$row,
            'on_invoice_discount' => Money::sub($row['gross_sales'], $row['net_sales']),
            'gross_margin' => $grossMargin,
            'net_net_margin' => $netNet,
            'on_invoice_discount_percent' => $percent(Money::sub($row['gross_sales'], $row['net_sales']), $row['gross_sales']),
            'gross_margin_percent' => $percent($grossMargin, $row['net_sales']),
            'net_net_margin_percent' => $percent($netNet, $row['net_sales']),
        ];
    }
}
