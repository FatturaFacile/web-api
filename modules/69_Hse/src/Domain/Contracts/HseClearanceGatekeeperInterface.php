<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\Contracts;

use Modules\Hse\Domain\ValueObjects\ClearanceRequest;
use Modules\Hse\Domain\ValueObjects\ClearanceResult;

/**
 * The hard safety block consumed synchronously by MES dispatch (Mod 064), field service (Mod 084) and construction
 * site access (Mod 108): a worker with any expired, missing or unfit requirement is not cleared.
 */
interface HseClearanceGatekeeperInterface
{
    public function evaluate(ClearanceRequest $request): ClearanceResult;
}
