<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;
use Modules\Inventory\Models\InventoryAdjustment;

final readonly class InventoryAdjustmentPostedEvent implements DomainEventInterface
{
    /**
     * @param  array<string, mixed>  $lines
     */
    public function __construct(
        public string $adjustmentId,
        public string $warehouseId,
        public string $adjustmentType,
        public string $reasonCode,
        public float $totalCostImpact,
        public array $lines,
        public string $companyId,
        public string $tenantId = '',
        public string $eventId = '',
        public string $occurredAt = ''
    ) {}

    /**
     * The event of an adjustment whose stock movements were just executed: the cost impact is the sum of quantity delta
     * times unit cost of the lines, positive when stock was added.
     *
     * @param  array<int, array{stock_item_id: string, quantity_delta: float|int|string, unit_cost: float|int|string}>  $lines
     */
    public static function forAdjustment(InventoryAdjustment $adjustment, array $lines): self
    {
        $impact = 0.0;
        $eventLines = [];

        foreach ($lines as $line) {
            $lineImpact = round((float) $line['quantity_delta'] * (float) $line['unit_cost'], 4);
            $impact += $lineImpact;
            $eventLines[] = [
                'item_id' => (string) $line['stock_item_id'],
                'quantity_delta' => (float) $line['quantity_delta'],
                'unit_cost' => (float) $line['unit_cost'],
                'cost_impact' => $lineImpact,
            ];
        }

        $increases = array_filter($eventLines, static fn (array $line): bool => $line['quantity_delta'] > 0);

        return new self(
            adjustmentId: (string) $adjustment->id,
            warehouseId: (string) $adjustment->warehouse_id,
            adjustmentType: match (true) {
                count($increases) === count($eventLines) => 'increase',
                $increases === [] => 'decrease',
                default => 'mixed',
            },
            reasonCode: (string) ($adjustment->reason ?? 'adjustment'),
            totalCostImpact: round($impact, 2),
            lines: $eventLines,
            companyId: (string) ($adjustment->company_id ?? ''),
            tenantId: (string) $adjustment->tenant_id,
            eventId: (string) Str::uuid7(),
            occurredAt: now()->toIso8601String(),
        );
    }

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'stock_adjustment';
    }

    public function getAggregateId(): string
    {
        return $this->adjustmentId;
    }

    public function getEventName(): string
    {
        return 'inventory.adjustment_posted';
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getOccurredAt(): string
    {
        return $this->occurredAt !== '' ? $this->occurredAt : now()->toIso8601String();
    }

    public function toPayload(): array
    {
        return [
            'adjustment_id' => $this->adjustmentId,
            'warehouse_id' => $this->warehouseId,
            'adjustment_type' => $this->adjustmentType,
            'reason_code' => $this->reasonCode,
            'total_cost_impact' => $this->totalCostImpact,
            'lines' => $this->lines,
            'company_id' => $this->companyId,
            'tenant_id' => $this->tenantId,
        ];
    }
}
