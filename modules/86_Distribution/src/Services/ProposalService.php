<?php

declare(strict_types=1);

namespace Modules\Distribution\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Distribution\Domain\Enums\ProposalStatus;
use Modules\Distribution\Models\ReplenishmentProposal;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\TransferOrder;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Support\Quantity;

/**
 * Decisions on replenishment proposals: adjust the quantity, reject, or approve in bulk into transfer orders grouped by
 * (source warehouse, destination warehouse). Proposals the hub could not cover stay flagged as shortfalls.
 */
final readonly class ProposalService
{
    public function __construct(private TransferWorkflowService $transfers) {}

    public function adjust(ReplenishmentProposal $proposal, string|int|float $quantity): ReplenishmentProposal
    {
        if ($proposal->status !== ProposalStatus::Pending) {
            throw new StockRuleViolation('Only a pending proposal can be adjusted.');
        }

        $quantity = Quantity::of($quantity);

        if (! Quantity::isPositive($quantity)) {
            throw new StockRuleViolation('The proposed quantity must be greater than zero.');
        }

        $proposal->update(['suggested_quantity' => $quantity]);

        return $proposal->refresh();
    }

    public function reject(ReplenishmentProposal $proposal, ?string $userId): ReplenishmentProposal
    {
        if (! in_array($proposal->status, [ProposalStatus::Pending, ProposalStatus::Shortfall], true)) {
            throw new StockRuleViolation('Only an open proposal can be rejected.');
        }

        $proposal->update(['status' => ProposalStatus::Rejected->value, 'decided_by' => $userId, 'decided_at' => Carbon::now()]);

        return $proposal->refresh();
    }

    /**
     * @param  list<string>  $ids
     * @param  'draft'|'approved'  $transferStatus
     * @return array{transfers: list<TransferOrder>, shortfalls: list<ReplenishmentProposal>}
     */
    public function approve(array $ids, string $transferStatus, ?string $userId): array
    {
        $proposals = ReplenishmentProposal::query()->whereIn('id', $ids)->orderBy('created_at')->get();

        if ($proposals->count() !== count(array_unique($ids))) {
            throw new StockRuleViolation('Some proposals do not exist.');
        }

        $shortfalls = $proposals->filter(static fn (ReplenishmentProposal $proposal): bool => $proposal->status === ProposalStatus::Shortfall)->values()->all();
        $open = $proposals->filter(static fn (ReplenishmentProposal $proposal): bool => $proposal->status === ProposalStatus::Pending);

        foreach ($proposals as $proposal) {
            if (! in_array($proposal->status, [ProposalStatus::Pending, ProposalStatus::Shortfall], true)) {
                throw new StockRuleViolation('Proposal already decided: '.$proposal->id);
            }
        }

        $groups = $open->groupBy(static fn (ReplenishmentProposal $proposal): string => $proposal->source_warehouse_id.'|'.$proposal->warehouse_id);
        $orders = [];

        DB::transaction(function () use ($groups, $transferStatus, $userId, &$orders): void {
            foreach ($groups as $group) {
                /** @var ReplenishmentProposal $first */
                $first = $group->first();

                if ($first->source_warehouse_id === null || ! Quantity::isPositive((string) $first->suggested_quantity)) {
                    throw new StockRuleViolation('A proposal without a source warehouse or a quantity cannot become a transfer.');
                }

                $lines = [];

                foreach ($group as $proposal) {
                    $lines[$proposal->stock_item_id] = ['stock_item_id' => (string) $proposal->stock_item_id, 'quantity' => Quantity::add($lines[$proposal->stock_item_id]['quantity'] ?? '0', (string) $proposal->suggested_quantity)];
                }

                $order = $this->transfers->create(
                    Warehouse::query()->findOrFail($first->source_warehouse_id),
                    Warehouse::query()->findOrFail($first->warehouse_id),
                    array_values($lines),
                    $userId,
                    null,
                    null,
                    $transferStatus === 'approved',
                );

                foreach ($group as $proposal) {
                    $proposal->update([
                        'status' => ProposalStatus::Converted->value,
                        'transfer_order_id' => $order->id,
                        'decided_by' => $userId,
                        'decided_at' => Carbon::now(),
                    ]);
                }

                $orders[] = $order;
            }
        });

        return ['transfers' => $orders, 'shortfalls' => $shortfalls];
    }
}
