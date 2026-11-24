<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Contracts;

use Modules\Manufacturing\Models\MfgProductionOrder;

/**
 * Standard against actual costing of production orders, from the data the order recorded.
 */
interface ProductionCostingServiceInterface
{
    /**
     * Cost report of the order per element (material, labor, overhead, subcontract): standard for the planned quantity,
     * standard for the output received, actual cost charged to WIP and variance; WIP balance and the journal entries
     * that posted the order. Amounts are decimal strings.
     *
     * @return array{production_order_id: string, status: string, currency: string, target_quantity: string, received_quantity: string, scrapped_quantity: string, standard_unit_cost: string, elements: array<string, array{standard_unit_cost: string, standard_for_target: string, standard_for_output: string, actual: string, variance: string}>, totals: array{standard_for_target: string, standard_for_output: string, actual: string, received_value: string, wip_balance: string, variance: string}, journal_entry_ids: list<string>, is_closed: bool}
     */
    public function costReport(MfgProductionOrder $order): array;
}
