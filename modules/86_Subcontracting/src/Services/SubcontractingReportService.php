<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Services;

use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Customs\Models\CustomsDeclaration;
use Modules\Manufacturing\Support\Money;
use Modules\Subcontracting\Domain\Enums\CustomsProcessingRegime;
use Modules\Subcontracting\Domain\Enums\DeadlineStatus;
use Modules\Subcontracting\Domain\Enums\Direction;
use Modules\Subcontracting\Domain\Enums\LedgerMovementType;
use Modules\Subcontracting\Domain\Enums\OrderStatus;
use Modules\Subcontracting\Models\SubcontractingOrder;
use Modules\Subcontracting\Models\SubcontractingOrderComponent;
use Modules\Subcontracting\Models\SubcontractMaterialLedgerEntry;
use Modules\Subcontracting\Support\StatutoryDeadline;

/**
 * Read side of the module: the stock-at-subcontractors dashboard, the statutory register "Registro delle merci in conto lavoro"
 * (Art. 53 DPR 633/72, DPR 441/1997) and the IT/CH cross-border processing summary (TPP / TPA).
 *
 * @phpstan-type RegisterFilters array{direction?: string|null, partner_id?: string|null, product_id?: string|null, order_id?: string|null, movement_type?: string|null, from?: string|null, to?: string|null, deadline_status?: string|null}
 */
final class SubcontractingReportService
{
    /**
     * Goods held by partners, per partner / product / order, with aging and the statutory deadline.
     *
     * @param  array{direction?: string|null, partner_id?: string|null, product_id?: string|null, lot_id?: string|null, deadline_status?: string|null}  $filters
     * @return array{kpis: array<string, int|string>, rows: list<array<string, mixed>>, by_partner: list<array<string, mixed>>}
     */
    public function dashboard(string $tenantId, array $filters = []): array
    {
        $today = CarbonImmutable::today();
        $openOrders = SubcontractingOrder::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', array_map(static fn (OrderStatus $s): string => $s->value, OrderStatus::open()))
            ->get();

        $kpis = [
            'active_passive_orders' => $openOrders->where('direction', Direction::Passive)->count(),
            'active_customer_orders' => $openOrders->where('direction', Direction::Active)->count(),
            'active_customs_regimes' => $openOrders->filter(static fn (SubcontractingOrder $o): bool => $o->customs_regime->isCrossBorder())->count(),
        ];

        $orders = $openOrders
            ->when($filters['direction'] ?? null, static fn (Collection $c, string $d): Collection => $c->filter(static fn (SubcontractingOrder $o): bool => $o->direction->value === $d))
            ->when($filters['partner_id'] ?? null, static fn (Collection $c, string $p): Collection => $c->where('partner_id', $p))
            ->keyBy('id');

        $components = $orders->isEmpty() ? collect() : SubcontractingOrderComponent::query()
            ->whereIn('subcontracting_order_id', $orders->keys())
            ->when($filters['product_id'] ?? null, static fn ($q, string $p) => $q->where('component_product_id', $p))
            ->when($filters['lot_id'] ?? null, static fn ($q, string $l) => $q->where('lot_id', $l))
            ->get();

        $clock = $this->clockByPair($orders->keys()->all());
        $products = Product::query()->whereIn('id', $components->pluck('component_product_id')->unique())->get(['id', 'sku', 'name'])->keyBy('id');
        $rows = [];
        $unitsHeld = '0';
        $expiring = 0;
        $overdue = 0;

        foreach ($components as $component) {
            $held = $component->heldQuantity();

            if (! Money::isPositive($held)) {
                continue;
            }

            $order = $orders[$component->subcontracting_order_id];
            $pair = $clock[$component->subcontracting_order_id.'|'.$component->component_product_id] ?? ['deadline' => null, 'since' => null];
            $deadline = $pair['deadline'] !== null ? CarbonImmutable::parse($pair['deadline']) : null;
            $status = StatutoryDeadline::status($deadline, $held, $today);

            if (($filters['deadline_status'] ?? null) !== null && $status->value !== $filters['deadline_status']) {
                continue;
            }

            $unitsHeld = Money::add($unitsHeld, $held);
            $expiring += $status === DeadlineStatus::Warning ? 1 : 0;
            $overdue += $status === DeadlineStatus::Expired ? 1 : 0;
            $since = $pair['since'] !== null ? CarbonImmutable::parse($pair['since']) : null;

            $rows[] = [
                'order_id' => (string) $order->id,
                'order_number' => $order->order_number,
                'direction' => $order->direction->value,
                'partner_id' => $order->partner_id,
                'partner_name' => $order->partner_name,
                'warehouse_id' => $order->subcontractor_warehouse_id,
                'product_id' => $component->component_product_id,
                'sku' => $products[$component->component_product_id]->sku ?? null,
                'product_name' => $products[$component->component_product_id]->name ?? null,
                'lot_id' => $component->lot_id,
                'held_qty' => $held,
                'held_since' => $since?->toDateString(),
                'aging_days' => $since !== null ? (int) $since->diffInDays($today) : null,
                'statutory_return_deadline' => $deadline?->toDateString(),
                'days_to_deadline' => $deadline !== null ? StatutoryDeadline::daysLeft($deadline, $today) : null,
                'deadline_status' => $status->value,
                'customs_regime' => $order->customs_regime->value,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['partner_name'], $a['statutory_return_deadline']] <=> [$b['partner_name'], $b['statutory_return_deadline']]);

        $byPartner = [];
        foreach ($rows as $row) {
            $key = $row['direction'].'|'.$row['partner_id'];
            $byPartner[$key] ??= ['partner_id' => $row['partner_id'], 'partner_name' => $row['partner_name'], 'direction' => $row['direction'], 'held_qty' => '0', 'open_orders' => [], 'worst_deadline_status' => DeadlineStatus::Ok->value];
            $byPartner[$key]['held_qty'] = Money::qty(Money::add($byPartner[$key]['held_qty'], $row['held_qty']));
            $byPartner[$key]['open_orders'][$row['order_id']] = true;
            $byPartner[$key]['worst_deadline_status'] = $this->worse($byPartner[$key]['worst_deadline_status'], $row['deadline_status']);
        }

        return [
            'kpis' => [...$kpis, 'units_held' => Money::qty($unitsHeld), 'expiring_30d' => $expiring, 'overdue_deadlines' => $overdue],
            'rows' => $rows,
            'by_partner' => array_values(array_map(static fn (array $p): array => [...$p, 'open_orders' => count($p['open_orders'])], $byPartner)),
        ];
    }

    /**
     * The statutory register, newest first, with the one-year deadline countdown of every line.
     *
     * @param  RegisterFilters  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function register(string $tenantId, array $filters, int $perPage, int $page = 1): LengthAwarePaginator
    {
        $query = $this->registerQuery($tenantId, $filters);
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $rows = $this->decorate($paginator->getCollection());

        if (($filters['deadline_status'] ?? null) !== null) {
            $rows = $rows->where('deadline_status', $filters['deadline_status'])->values();
        }

        return new LengthAwarePaginator($rows->all(), $paginator->total(), $paginator->perPage(), $paginator->currentPage());
    }

    /**
     * @param  RegisterFilters  $filters
     * @return list<array<string, mixed>>
     */
    public function registerRows(string $tenantId, array $filters): array
    {
        $rows = $this->decorate($this->registerQuery($tenantId, $filters)->get());

        if (($filters['deadline_status'] ?? null) !== null) {
            $rows = $rows->where('deadline_status', $filters['deadline_status'])->values();
        }

        return $rows->all();
    }

    /**
     * TPP / TPA jobs with their customs declarations: what went out, what came back (re-import / re-export balance) and the dates
     * that matter.
     *
     * @return array{regimes: list<array<string, mixed>>, orders: list<array<string, mixed>>}
     */
    public function crossBorder(string $tenantId, ?string $regime = null): array
    {
        $orders = SubcontractingOrder::query()
            ->where('tenant_id', $tenantId)
            ->where('customs_regime', '!=', CustomsProcessingRegime::None->value)
            ->when($regime, static fn ($q, string $r) => $q->where('customs_regime', $r))
            ->with('components')
            ->orderBy('expected_return_date')
            ->get();

        $declarations = CustomsDeclaration::query()->whereIn('id', $orders->pluck('customs_declaration_id')->filter()->unique())->get()->keyBy('id');
        $today = CarbonImmutable::today();
        $clock = $this->clockByPair($orders->pluck('id')->all());
        $rows = [];

        foreach ($orders as $order) {
            $out = (string) $order->components->reduce(static fn (string $sum, SubcontractingOrderComponent $c): string => Money::add($sum, $c->dispatched_qty), '0');
            $back = $order->direction === Direction::Active ? $order->delivered_quantity : $order->received_quantity;
            $declaration = $declarations[$order->customs_declaration_id] ?? null;
            $deadlines = array_filter([
                $declaration?->transit_deadline_on?->toDateString(),
                $order->expected_return_date?->toDateString(),
                ...array_map(static fn (array $p): ?string => $p['deadline'], array_filter($clock, static fn (string $key): bool => str_starts_with($key, $order->id.'|'), ARRAY_FILTER_USE_KEY)),
            ]);
            $expiry = $deadlines === [] ? null : min($deadlines);

            $rows[] = [
                'order_id' => (string) $order->id,
                'order_number' => $order->order_number,
                'direction' => $order->direction->value,
                'customs_regime' => $order->customs_regime->value,
                'status' => $order->status->value,
                'partner_name' => $order->partner_name,
                'output_product_id' => $order->output_product_id,
                'planned_quantity' => Money::qty($order->planned_quantity),
                'material_out_qty' => Money::qty($out),
                'goods_back_qty' => Money::qty($back),
                'balance_qty' => Money::qty(Money::sub($order->planned_quantity, Money::add($back, $order->scrap_quantity))),
                'customs_declaration_id' => $order->customs_declaration_id,
                'declaration_number' => $declaration?->trader_declaration_number,
                'declaration_mrn' => $declaration?->mrn,
                'declaration_status' => $declaration?->status->value,
                'customs_expiry_date' => $expiry,
                'days_to_expiry' => $expiry !== null ? StatutoryDeadline::daysLeft(CarbonImmutable::parse($expiry), $today) : null,
                'expired' => $expiry !== null && $order->status->isOpen() && CarbonImmutable::parse($expiry)->lt($today),
            ];
        }

        $regimes = [];
        foreach ($rows as $row) {
            $regimes[$row['customs_regime']] ??= ['customs_regime' => $row['customs_regime'], 'orders' => 0, 'open_orders' => 0, 'material_out_qty' => '0', 'goods_back_qty' => '0', 'balance_qty' => '0'];
            $regimes[$row['customs_regime']]['orders']++;
            $regimes[$row['customs_regime']]['open_orders'] += in_array($row['status'], array_map(static fn (OrderStatus $s): string => $s->value, OrderStatus::open()), true) ? 1 : 0;
            foreach (['material_out_qty', 'goods_back_qty', 'balance_qty'] as $field) {
                $regimes[$row['customs_regime']][$field] = Money::qty(Money::add($regimes[$row['customs_regime']][$field], $row[$field]));
            }
        }

        return ['regimes' => array_values($regimes), 'orders' => $rows];
    }

    /**
     * @param  RegisterFilters  $filters
     * @return Builder<SubcontractMaterialLedgerEntry>
     */
    private function registerQuery(string $tenantId, array $filters)
    {
        return SubcontractMaterialLedgerEntry::query()
            ->where('tenant_id', $tenantId)
            ->when($filters['direction'] ?? null, static fn ($q, string $v) => $q->where('direction', $v))
            ->when($filters['partner_id'] ?? null, static fn ($q, string $v) => $q->where('partner_id', $v))
            ->when($filters['product_id'] ?? null, static fn ($q, string $v) => $q->where('component_product_id', $v))
            ->when($filters['order_id'] ?? null, static fn ($q, string $v) => $q->where('subcontracting_order_id', $v))
            ->when($filters['movement_type'] ?? null, static fn ($q, string $v) => $q->where('movement_type', $v))
            ->when($filters['from'] ?? null, static fn ($q, string $v) => $q->whereDate('entry_date', '>=', $v))
            ->when($filters['to'] ?? null, static fn ($q, string $v) => $q->whereDate('entry_date', '<=', $v))
            ->orderByDesc('entry_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * @param  Collection<int, SubcontractMaterialLedgerEntry>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function decorate(Collection $entries): Collection
    {
        $orders = SubcontractingOrder::query()->whereIn('id', $entries->pluck('subcontracting_order_id')->unique())->get()->keyBy('id');
        $products = Product::query()->whereIn('id', $entries->pluck('component_product_id')->unique())->get(['id', 'sku', 'name'])->keyBy('id');
        $balances = $this->currentBalances($entries->pluck('subcontracting_order_id')->unique()->all());
        $today = CarbonImmutable::today();

        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = $entries->map(function (SubcontractMaterialLedgerEntry $entry) use ($orders, $products, $balances, $today): array {
            $order = $orders[$entry->subcontracting_order_id] ?? null;
            $remaining = $balances[$entry->subcontracting_order_id.'|'.$entry->component_product_id] ?? '0';
            $status = StatutoryDeadline::status($entry->statutory_return_deadline, $remaining, $today);

            return [
                'id' => (string) $entry->id,
                'entry_date' => $entry->entry_date->toDateString(),
                'direction' => $entry->direction->value,
                'movement_type' => $entry->movement_type->value,
                'order_id' => (string) $entry->subcontracting_order_id,
                'order_number' => $order?->order_number,
                'partner_id' => $entry->partner_id,
                'partner_name' => $order?->partner_name,
                'product_id' => $entry->component_product_id,
                'sku' => $products[$entry->component_product_id]->sku ?? null,
                'product_name' => $products[$entry->component_product_id]->name ?? null,
                'lot_id' => $entry->lot_id,
                'dispatched_qty' => Money::qty($entry->dispatched_qty),
                'consumed_in_returned_goods_qty' => Money::qty($entry->consumed_in_returned_goods_qty),
                'scrap_reported_by_subcontractor_qty' => Money::qty($entry->scrap_reported_by_subcontractor_qty),
                'returned_unused_qty' => Money::qty($entry->returned_unused_qty),
                'remaining_at_subcontractor_qty' => Money::qty($entry->remaining_at_subcontractor_qty),
                'ddt_document_id' => $entry->ddt_document_id,
                'ddt_number' => $entry->ddt_number,
                'ddt_date' => $entry->ddt_date?->toDateString(),
                'transport_reason' => $entry->transport_reason,
                'statutory_return_deadline' => $entry->statutory_return_deadline?->toDateString(),
                'days_to_deadline' => $entry->statutory_return_deadline !== null ? StatutoryDeadline::daysLeft($entry->statutory_return_deadline, $today) : null,
                'deadline_status' => $status->value,
                'customs_declaration_id' => $entry->customs_declaration_id,
                'valuation_amount' => Money::round($entry->valuation_amount, 2),
            ];
        })->values();

        return $rows;
    }

    /**
     * Quantity still held per order and product (the balance of the latest ledger line).
     *
     * @param  list<string>  $orderIds
     * @return array<string, string>
     */
    private function currentBalances(array $orderIds): array
    {
        $balances = [];

        SubcontractMaterialLedgerEntry::query()
            ->whereIn('subcontracting_order_id', $orderIds)
            ->orderBy('entry_date')->orderBy('created_at')->orderBy('id')
            ->get(['subcontracting_order_id', 'component_product_id', 'remaining_at_subcontractor_qty'])
            ->each(static function (SubcontractMaterialLedgerEntry $entry) use (&$balances): void {
                $balances[$entry->subcontracting_order_id.'|'.$entry->component_product_id] = (string) $entry->remaining_at_subcontractor_qty;
            });

        return $balances;
    }

    /**
     * The earliest statutory deadline and the oldest dispatch date per order and product.
     *
     * @param  list<string>  $orderIds
     * @return array<string, array{deadline: string|null, since: string|null}>
     */
    private function clockByPair(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $clock = [];

        SubcontractMaterialLedgerEntry::query()
            ->whereIn('subcontracting_order_id', $orderIds)
            ->whereIn('movement_type', [LedgerMovementType::OutboundDispatch->value, LedgerMovementType::CustomerMaterialReceipt->value])
            ->get(['subcontracting_order_id', 'component_product_id', 'statutory_return_deadline', 'ddt_date', 'entry_date'])
            ->each(static function (SubcontractMaterialLedgerEntry $entry) use (&$clock): void {
                $key = $entry->subcontracting_order_id.'|'.$entry->component_product_id;
                $deadline = $entry->statutory_return_deadline?->toDateString();
                $since = ($entry->ddt_date ?? $entry->entry_date)->toDateString();
                $knownDeadline = $clock[$key]['deadline'] ?? null;
                $knownSince = $clock[$key]['since'] ?? null;
                $clock[$key]['deadline'] = $knownDeadline !== null && ($deadline === null || $knownDeadline <= $deadline) ? $knownDeadline : $deadline;
                $clock[$key]['since'] = $knownSince !== null && $knownSince <= $since ? $knownSince : $since;
            });

        return $clock;
    }

    private function worse(string $current, string $candidate): string
    {
        $rank = [DeadlineStatus::Closed->value => 0, DeadlineStatus::Ok->value => 1, DeadlineStatus::Warning->value => 2, DeadlineStatus::Expired->value => 3];

        return $rank[$candidate] > $rank[$current] ? $candidate : $current;
    }
}
