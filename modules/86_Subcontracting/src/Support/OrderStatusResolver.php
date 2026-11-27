<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Support;

use Modules\Subcontracting\Domain\Enums\Direction;
use Modules\Subcontracting\Domain\Enums\OrderStatus;
use Modules\Subcontracting\Models\SubcontractingOrder;

/**
 * Derives the lifecycle status of an order from its quantities, after every movement.
 */
final class OrderStatusResolver
{
    public static function resolve(SubcontractingOrder $order): OrderStatus
    {
        $components = $order->components()->get();
        $dispatched = '0';
        $held = '0';
        $toDispatch = '0';

        foreach ($components as $component) {
            $dispatched = bcadd($dispatched, (string) $component->dispatched_qty, 4);
            $held = bcadd($held, $component->heldQuantity(), 4);
            $toDispatch = bcadd($toDispatch, $component->toDispatchQuantity(), 4);
        }

        $active = $order->direction === Direction::Active;
        $done = (string) ($active ? $order->delivered_quantity : $order->received_quantity);
        $finished = bccomp(bcadd($done, (string) $order->scrap_quantity, 4), (string) $order->planned_quantity, 4) >= 0;

        return match (true) {
            bccomp($dispatched, '0', 4) === 0 => $order->status,
            $finished && bccomp($held, '0', 4) === 0 => OrderStatus::Completed,
            bccomp($done, '0', 4) > 0 => OrderStatus::PartiallyReturned,
            $active && bccomp((string) $order->received_quantity, '0', 4) > 0 => OrderStatus::InProgress,
            bccomp($toDispatch, '0', 4) === 0 => OrderStatus::InProgress,
            default => OrderStatus::MaterialsDispatched,
        };
    }
}
