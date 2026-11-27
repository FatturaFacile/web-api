<?php

declare(strict_types=1);

namespace Modules\Distribution\Services;

use Modules\Distribution\Domain\Enums\AlertType;
use Modules\Distribution\Domain\Enums\ProposalStatus;
use Modules\Distribution\Domain\Enums\RunStatus;
use Modules\Distribution\Models\DrpAlert;
use Modules\Distribution\Models\DrpRun;
use Modules\Distribution\Models\ReplenishmentProposal;
use Modules\Inventory\Models\TransferOrder;
use Modules\Inventory\Support\Quantity;

/**
 * KPIs of the distribution overview: active transfers, units and value in transit, overdue shipments, open proposals, stockout
 * alerts of the latest DRP run and the value of the consignment stock.
 */
final class DashboardService
{
    public function __construct(
        private readonly InTransitService $inTransit,
        private readonly ConsignmentService $consignment,
    ) {}

    /**
     * @return array{active_transfers: int, units_in_transit: string, value_in_transit: string, overdue_shipments: int, pending_proposals: int, stockout_alerts: int, consignment_stock_value: string, overdue: list<array<string, mixed>>, latest_run: DrpRun|null}
     */
    public function overview(): array
    {
        $transit = $this->inTransit->lines();
        $overdue = array_values(array_filter($transit['lines'], static fn (array $line): bool => $line['is_overdue']));
        $latest = DrpRun::query()->where('status', RunStatus::Completed->value)->orderByDesc('started_at')->first();
        $stockouts = $latest === null ? 0 : DrpAlert::query()->where('drp_run_id', $latest->id)->where('alert_type', AlertType::StockoutRisk->value)->count();
        $consignment = $this->consignment->stock();

        return [
            'active_transfers' => TransferOrder::query()->whereIn('status', ['approved', 'in_transit', 'partially_received'])->count(),
            'units_in_transit' => $transit['total_units'],
            'value_in_transit' => $transit['total_value'],
            'overdue_shipments' => count($overdue),
            'pending_proposals' => ReplenishmentProposal::query()->where('status', ProposalStatus::Pending->value)->count(),
            'stockout_alerts' => $stockouts,
            'consignment_stock_value' => Quantity::of($consignment['total_value'], 2),
            'overdue' => array_slice($overdue, 0, 10),
            'latest_run' => $latest,
        ];
    }
}
