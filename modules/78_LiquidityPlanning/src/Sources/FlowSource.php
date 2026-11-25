<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Sources;

use Modules\LiquidityPlanning\Support\Flow;

/**
 * A source of expected cash flows of the forecast (a ledger, an order book, a contract register...). A source with no
 * record returns an empty list; it never fails because an optional module has nothing to report.
 */
interface FlowSource
{
    /**
     * @return list<Flow>
     */
    public function flows(SourceContext $context): array;
}
