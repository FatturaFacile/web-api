<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Contracts;

use Modules\Manufacturing\DTOs\ConfirmOperationDTO;
use Modules\Manufacturing\Models\MfgMaterialMovement;
use Modules\Manufacturing\Models\MfgOperationConfirmation;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;

/**
 * Lifecycle of production orders: release with component reservation, material issue and backflush, operation
 * confirmations, output receipt, completion, close with variance settlement, cancellation.
 */
interface ProductionExecutionServiceInterface
{
    /**
     * Releases a planned order: freezes the standard cost, explodes the components, reserves them and copies and
     * schedules the routing operations.
     */
    public function releaseOrder(MfgProductionOrder $order, ?string $actorUserId = null): MfgProductionOrder;

    /**
     * Marks a released order as started on the shop floor.
     */
    public function startOrder(MfgProductionOrder $order, ?string $actorUserId = null): MfgProductionOrder;

    /**
     * Issues components to the order (Dr WIP, Cr raw materials at the inventory valuation).
     *
     * @param  list<array{line_id: string, quantity: string, lot_id?: string|null}>  $lines
     * @return list<MfgMaterialMovement>
     */
    public function issueMaterials(MfgProductionOrder $order, array $lines, ?string $postingDate = null, bool $allowOverIssue = false, ?string $actorUserId = null): array;

    /**
     * Confirms an operation with actual times and quantities, absorbs labour and overhead into WIP and backflushes the
     * components of the operation.
     */
    public function confirmOperation(MfgProductionOrder $order, MfgProductionOrderOperation $operation, ConfirmOperationDTO $dto, ?string $actorUserId = null): MfgOperationConfirmation;

    /**
     * Receives output into stock at the standard unit cost (Dr finished goods, Cr WIP), backflushing the components
     * not tied to an operation.
     *
     * @param  list<string>  $serialNumbers
     */
    public function receiveOutput(MfgProductionOrder $order, string $quantity, ?string $lotNumber = null, array $serialNumbers = [], ?string $warehouseId = null, ?string $postingDate = null, ?string $actorUserId = null): MfgMaterialMovement;

    /**
     * Completes the order technically: no more receipts, remaining reservations released.
     */
    public function completeOrder(MfgProductionOrder $order, ?string $actorUserId = null): MfgProductionOrder;

    /**
     * Closes a completed order: computes the variances from the actual costs and settles the WIP balance.
     */
    public function closeOrder(MfgProductionOrder $order, ?string $postingDate = null, ?string $actorUserId = null): MfgProductionOrder;

    /**
     * Cancels an order that moved nothing yet, releasing its reservations.
     */
    public function cancelOrder(MfgProductionOrder $order, string $reason, ?string $actorUserId = null): MfgProductionOrder;
}
