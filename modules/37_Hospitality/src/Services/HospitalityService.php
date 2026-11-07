<?php

declare(strict_types=1);

namespace Modules\Hospitality\Services;

use App\Actions\Retail\RegisterPosSale;
use App\Exceptions\BusinessRuleException;
use App\Models\PosSession;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Hospitality\Models\DiningTable;
use Modules\Hospitality\Models\KitchenTicket;
use Modules\Hospitality\Models\Order;
use Modules\Hospitality\Models\OrderItem;
use Modules\Hospitality\Models\OrderPayment;
use Modules\Hospitality\Models\Room;

/**
 * Table service: orders by course, kitchen tickets per station, and the bill paid at once or split, each part
 * registered as a sale of the POS (stock, loyalty and fiscal receipt follow the POS flow).
 */
final class HospitalityService
{
    public function __construct(private readonly RegisterPosSale $registerSale) {}

    /**
     * The plan of a room with the open order of each table.
     *
     * @return array<string, mixed>
     */
    public function floorPlan(Room $room): array
    {
        $tables = $room->tables()->where('is_active', true)->get();
        $orders = Order::query()->whereIn('table_id', $tables->pluck('id'))->where('status', 'open')->get()->keyBy('table_id');

        return [
            'room' => $room->only(['id', 'name', 'width', 'height']),
            'tables' => $tables->map(static fn (DiningTable $table): array => [
                ...$table->only(['id', 'name', 'seats', 'shape', 'pos_x', 'pos_y', 'width', 'height']),
                'status' => $orders->has($table->id) ? 'occupied' : 'free',
                'order_id' => $orders->get($table->id)?->id,
                'covers' => $orders->get($table->id)?->covers,
            ])->values()->all(),
        ];
    }

    public function openOrder(DiningTable $table, int $covers, ?string $userId, ?string $notes): Order
    {
        return DB::transaction(function () use ($table, $covers, $userId, $notes): Order {
            DiningTable::query()->lockForUpdate()->findOrFail($table->id);

            if (! $table->is_active) {
                throw new BusinessRuleException('The table is not in service.');
            }

            if (Order::query()->where('table_id', $table->id)->where('status', 'open')->exists()) {
                throw new BusinessRuleException('The table already has an open order.');
            }

            $order = new Order;
            $order->fill(['table_id' => $table->id, 'covers' => $covers, 'status' => 'open', 'opened_by' => $userId, 'notes' => $notes]);
            $order->save();

            return $order;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return Collection<int, OrderItem>
     */
    public function addItems(Order $order, array $items): Collection
    {
        $this->assertOpen($order);
        $products = Product::query()->whereIn('id', collect($items)->pluck('product_id')->filter())->get()->keyBy('id');

        return DB::transaction(fn (): Collection => collect($items)->map(function (array $item) use ($order, $products): OrderItem {
            $product = isset($item['product_id']) ? $products->get($item['product_id']) : null;
            $model = new OrderItem;
            $model->fill([
                'order_id' => $order->id,
                'product_id' => $product?->id,
                'description' => $item['description'] ?? $product?->name,
                'quantity' => (string) $item['quantity'],
                'unit_price' => (string) ($item['unit_price'] ?? $product?->sale_price ?? '0'),
                'tax_rate' => (string) ($item['tax_rate'] ?? $product?->tax_rate ?? '0'),
                'course' => (int) ($item['course'] ?? 1),
                'station' => $item['station'] ?? 'kitchen',
                'notes' => $item['notes'] ?? null,
                'status' => 'pending',
            ]);
            $model->save();

            return $model;
        }));
    }

    /**
     * Sends the pending items of a course to their stations: one ticket per station.
     *
     * @return Collection<int, KitchenTicket>
     */
    public function fireCourse(Order $order, int $course): Collection
    {
        $this->assertOpen($order);

        return DB::transaction(function () use ($order, $course): Collection {
            $items = OrderItem::query()->where('order_id', $order->id)->where('course', $course)->where('status', 'pending')->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw new BusinessRuleException("Course {$course} has nothing left to send.");
            }

            $tickets = $items->groupBy('station')->map(function (Collection $stationItems, string $station) use ($order, $course): KitchenTicket {
                $ticket = new KitchenTicket;
                $ticket->fill([
                    'order_id' => $order->id,
                    'station' => $station,
                    'course' => $course,
                    'status' => 'open',
                    'lines' => $stationItems->map(static fn (OrderItem $item): array => ['order_item_id' => $item->id, 'description' => $item->description, 'quantity' => (string) $item->quantity, 'notes' => $item->notes])->values()->all(),
                ]);
                $ticket->save();

                return $ticket;
            })->values();

            OrderItem::query()->whereIn('id', $items->pluck('id'))->update(['status' => 'sent', 'sent_at' => now()]);

            return $tickets;
        });
    }

    public function advanceTicket(KitchenTicket $ticket, string $target): KitchenTicket
    {
        $allowed = ['open' => ['ready'], 'ready' => ['served'], 'served' => []];

        if (! in_array($target, $allowed[$ticket->status] ?? [], true)) {
            throw new BusinessRuleException("A ticket cannot move from {$ticket->status} to {$target}.");
        }

        DB::transaction(function () use ($ticket, $target): void {
            $ticket->update(['status' => $target, 'ready_at' => $target === 'ready' ? now() : $ticket->ready_at]);
            OrderItem::query()->whereIn('id', collect($ticket->lines)->pluck('order_item_id'))->where('status', '!=', 'voided')->update(['status' => $target]);
        });

        return $ticket;
    }

    public function voidItem(OrderItem $item, string $reason): OrderItem
    {
        if (Decimal::isPositive((string) $item->paid_quantity)) {
            throw new BusinessRuleException('A paid item cannot be voided.');
        }

        $item->update(['status' => 'voided', 'void_reason' => $reason]);
        $this->closeIfSettled(Order::query()->findOrFail($item->order_id));

        return $item;
    }

    public function moveOrder(Order $order, DiningTable $target): Order
    {
        $this->assertOpen($order);

        return DB::transaction(function () use ($order, $target): Order {
            DiningTable::query()->lockForUpdate()->findOrFail($target->id);

            if (Order::query()->where('table_id', $target->id)->where('status', 'open')->exists()) {
                throw new BusinessRuleException('The target table already has an open order.');
            }

            $order->update(['table_id' => $target->id]);

            return $order;
        });
    }

    /**
     * Pays a part of the bill as one POS sale: chosen items and quantities, or an equal share of what is left among the
     * guests still to pay (the last share takes the remainder).
     *
     * @param  array<string, mixed>  $data
     */
    public function pay(Order $order, array $data): OrderPayment
    {
        return DB::transaction(function () use ($order, $data): OrderPayment {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertOpen($locked);
            $items = OrderItem::query()->where('order_id', $locked->id)->where('status', '!=', 'voided')->lockForUpdate()->get()->keyBy('id');
            $lines = $data['mode'] === 'items' ? $this->chosenLines($items, $data['lines']) : $this->equalShare($items, (int) $data['ways']);

            if ($lines === []) {
                throw new BusinessRuleException('Nothing is left to pay.');
            }

            $sale = $this->registerSale->execute(PosSession::query()->findOrFail($data['pos_session_id']), [
                'payment_method' => $data['payment_method'],
                'cash_amount' => $data['cash_amount'] ?? null,
                'cash_tendered' => $data['cash_tendered'] ?? null,
                'contact_id' => $data['contact_id'] ?? null,
                'items' => array_map(static fn (array $line): array => [
                    'product_id' => $items[$line['order_item_id']]->product_id,
                    'description' => $items[$line['order_item_id']]->description,
                    'quantity' => $line['quantity'],
                    'unit_price' => (string) $items[$line['order_item_id']]->unit_price,
                    'tax_rate' => (string) $items[$line['order_item_id']]->tax_rate,
                ], $lines),
            ]);

            foreach ($lines as $line) {
                $item = $items[$line['order_item_id']];
                $item->update(['paid_quantity' => Decimal::add((string) $item->paid_quantity, $line['quantity'])]);
            }

            $payment = new OrderPayment;
            $payment->fill(['order_id' => $locked->id, 'pos_sale_id' => $sale->id, 'mode' => $data['mode'], 'amount' => (string) $sale->total_gross, 'lines' => $lines]);
            $payment->save();

            $this->closeIfSettled($locked);

            return $payment;
        });
    }

    /**
     * What is left to pay, line by line.
     *
     * @return array{order_id: string, lines: list<array<string, string>>, remaining_gross: string}
     */
    public function bill(Order $order): array
    {
        $lines = [];
        $total = '0';

        foreach ($order->items()->where('status', '!=', 'voided')->get() as $item) {
            $remaining = $item->remainingQuantity();

            if (! Decimal::isPositive($remaining)) {
                continue;
            }

            $net = Decimal::round2(Decimal::mul($remaining, (string) $item->unit_price));
            $gross = Decimal::round2(Decimal::add($net, Decimal::div(Decimal::mul($net, (string) $item->tax_rate), '100')));
            $total = Decimal::add($total, $gross);
            $lines[] = ['order_item_id' => $item->id, 'description' => $item->description, 'remaining_quantity' => $this->trim($remaining), 'unit_price' => (string) $item->unit_price, 'tax_rate' => (string) $item->tax_rate, 'gross' => $gross];
        }

        return ['order_id' => $order->id, 'lines' => $lines, 'remaining_gross' => Decimal::round2($total)];
    }

    /**
     * @param  Collection<string, OrderItem>  $items
     * @param  list<array{order_item_id: string, quantity: string|int|float}>  $requested
     * @return list<array{order_item_id: string, quantity: string}>
     */
    private function chosenLines(Collection $items, array $requested): array
    {
        return array_map(function (array $line) use ($items): array {
            $item = $items->get($line['order_item_id']) ?? throw new BusinessRuleException('An item does not belong to the order.');
            $quantity = Decimal::normalize((string) $line['quantity']);

            if (Decimal::compare($quantity, $item->remainingQuantity()) > 0) {
                throw new BusinessRuleException("Only {$this->trim($item->remainingQuantity())} of {$item->description} are left to pay.");
            }

            return ['order_item_id' => $item->id, 'quantity' => $this->trim($quantity)];
        }, $requested);
    }

    /**
     * @param  Collection<string, OrderItem>  $items
     * @return list<array{order_item_id: string, quantity: string}>
     */
    private function equalShare(Collection $items, int $ways): array
    {
        $lines = [];

        foreach ($items as $item) {
            $remaining = $item->remainingQuantity();

            if (! Decimal::isPositive($remaining)) {
                continue;
            }

            $share = $ways <= 1 ? $remaining : bcdiv(Decimal::normalize($remaining), (string) $ways, 4);

            if (Decimal::isPositive($share)) {
                $lines[] = ['order_item_id' => $item->id, 'quantity' => $this->trim($share)];
            }
        }

        return $lines;
    }

    private function closeIfSettled(Order $order): void
    {
        $open = OrderItem::query()->where('order_id', $order->id)->where('status', '!=', 'voided')->get()
            ->contains(static fn (OrderItem $item): bool => Decimal::isPositive($item->remainingQuantity()));

        if (! $open && $order->payments()->exists()) {
            $order->update(['status' => 'closed', 'closed_at' => now()]);
        }
    }

    private function assertOpen(Order $order): void
    {
        if ($order->status !== 'open') {
            throw new BusinessRuleException('The order is closed.');
        }
    }

    private function trim(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
