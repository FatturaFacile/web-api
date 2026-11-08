<?php

declare(strict_types=1);

namespace Modules\Analytics\Kpis;

use App\Services\Tenancy\TenantClock;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Modules\Analytics\Contracts\KpiCalculator;
use Modules\Analytics\Support\ReportPeriod;
use Modules\GeneralLedger\Enums\ReportingClass;
use Modules\GeneralLedger\Services\LedgerBalanceReader;
use Modules\Inventory\Models\StockQuant;

/**
 * Value of the stock on hand today: quantity of each quant (moved only by the Inventory services) times the moving
 * average cost of its item. The book value of the inventory accounts is shown next to it, so a difference between the
 * warehouse and the ledger is visible. The cost of the past is not stored per date, so this is a snapshot of today.
 */
final readonly class StockValueKpi implements KpiCalculator
{
    private const int Scale = 4;

    /**
     * Inject the collaborators.
     */
    public function __construct(private TenantClock $clock, private TenantContext $tenantContext, private LedgerBalanceReader $ledger) {}

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'stock-value';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return 'Stock value';
    }

    /**
     * {@inheritDoc}
     */
    public function unit(): string
    {
        return 'currency';
    }

    /**
     * {@inheritDoc}
     */
    public function kind(): string
    {
        return 'snapshot';
    }

    /**
     * {@inheritDoc}
     */
    public function requiredModule(): string
    {
        return 'inventory';
    }

    /**
     * {@inheritDoc}
     */
    public function calculate(ReportPeriod $period): array
    {
        $today = $this->clock->today();
        $totals = $this->onHand()
            ->selectRaw('COUNT(DISTINCT stock_quants.stock_item_id) AS items')
            ->selectRaw('COALESCE(SUM(stock_quants.quantity_on_hand), 0) AS quantity')
            ->selectRaw('COALESCE(SUM(stock_quants.quantity_on_hand * stock_items.current_moving_average_cost), 0) AS value')
            ->selectRaw('COUNT(DISTINCT stock_quants.stock_item_id) FILTER (WHERE stock_items.current_moving_average_cost = 0) AS items_without_cost')
            ->first();

        $items = (int) $totals->items;

        return [
            'value' => $items === 0 ? null : bcadd((string) $totals->value, '0', 2),
            'details' => [
                'as_of' => $today->toDateString(),
                'cost_basis' => 'current_moving_average_cost',
                'item_count' => $items,
                'total_quantity' => $items === 0 ? null : bcadd((string) $totals->quantity, '0', 4),
                'items_without_cost' => (int) $totals->items_without_cost,
                'ledger_inventory_balance' => $this->ledgerBalance($today),
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function rows(ReportPeriod $period, int $limit): array
    {
        return $this->onHand()
            ->groupBy('stock_items.id', 'stock_items.sku', 'stock_items.name', 'stock_items.current_moving_average_cost')
            ->orderByRaw('SUM(stock_quants.quantity_on_hand * stock_items.current_moving_average_cost) DESC')
            ->limit($limit)
            ->selectRaw('stock_items.sku AS sku, stock_items.name AS item, SUM(stock_quants.quantity_on_hand) AS quantity, stock_items.current_moving_average_cost AS unit_cost, SUM(stock_quants.quantity_on_hand * stock_items.current_moving_average_cost) AS value')
            ->get()
            ->map(static fn (object $row): array => [
                'sku' => (string) $row->sku,
                'item' => (string) $row->item,
                'quantity' => bcadd((string) $row->quantity, '0', 4),
                'unit_cost' => bcadd((string) $row->unit_cost, '0', 4),
                'value' => bcadd((string) $row->value, '0', 2),
            ])
            ->all();
    }

    private function onHand(): Builder
    {
        return StockQuant::query()
            ->toBase()
            ->join('stock_items', 'stock_items.id', '=', 'stock_quants.stock_item_id')
            ->whereNull('stock_items.deleted_at')
            ->where('stock_quants.quantity_on_hand', '>', 0);
    }

    /**
     * Balance of the ledger accounts classed as inventory, or null when the ledger has none.
     */
    private function ledgerBalance(CarbonImmutable $today): ?string
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $accounts = $this->ledger->cumulative($tenantId, $today->startOfYear(), $today)
            ->filter(static fn (object $account): bool => ReportingClass::of($account)[0] === ReportingClass::Inventory);

        if ($accounts->isEmpty()) {
            return null;
        }

        return bcadd($accounts->reduce(fn (string $sum, object $account): string => bcadd($sum, $this->ledger->naturalAmount($account), self::Scale), '0'), '0', 2);
    }
}
