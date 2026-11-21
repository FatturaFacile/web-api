<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Support;

use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;

/**
 * The ideal cycle time of an order operation in milliseconds per piece, for the OEE performance of the MES Core: the
 * explicit value of the operation when it has one, otherwise the planned run time spread over the order quantity.
 */
final class IdealCycleTime
{
    public const string Explicit = 'Explicit';

    public const string Derived = 'Derived';

    public const string Missing = 'Missing';

    /**
     * Resolves the ideal cycle time and where it comes from. A derived value includes every planned allowance of the
     * run time, so it is flagged as such for the MES Core.
     *
     * @return array{ms: string|null, source: string}
     */
    public static function resolve(MfgProductionOrderOperation $operation, MfgProductionOrder $order): array
    {
        if ($operation->ideal_cycle_time_ms !== null && Money::isPositive($operation->ideal_cycle_time_ms)) {
            return ['ms' => Money::round($operation->ideal_cycle_time_ms, 3), 'source' => self::Explicit];
        }

        if (Money::isPositive($operation->planned_run_minutes) && Money::isPositive($order->target_quantity)) {
            $perPiece = Money::div(Money::mul($operation->planned_run_minutes, '60000'), $order->target_quantity);

            return ['ms' => Money::round($perPiece, 3), 'source' => self::Derived];
        }

        return ['ms' => null, 'source' => self::Missing];
    }
}
