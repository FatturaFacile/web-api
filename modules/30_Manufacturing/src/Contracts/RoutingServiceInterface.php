<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Contracts;

use Modules\Manufacturing\DTOs\CreateRoutingDTO;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgRouting;

/**
 * Routings: operations on work centres with setup, run and labour times.
 */
interface RoutingServiceInterface
{
    /**
     * Creates an active routing with its operations; a default routing becomes the item's default.
     */
    public function createRouting(CreateRoutingDTO $dto): MfgRouting;

    /**
     * The routing of the item effective on the date: the default one first.
     */
    public function effectiveRoutingFor(MfgItem $item, ?string $date = null): ?MfgRouting;

    /**
     * Standard times and costs of the routing for a quantity, per operation and in total, at the current work centre
     * rates: setup is counted once per quantity.
     *
     * @return array{operations: list<array{operation_id: string, sequence: int, work_center_id: string, machine_minutes: string, labor_minutes: string, labor_cost: string, overhead_cost: string, subcontract_cost: string}>, machine_minutes: string, labor_minutes: string, labor_cost: string, overhead_cost: string, subcontract_cost: string}
     */
    public function standardTimesAndCosts(MfgRouting $routing, string $quantity): array;
}
