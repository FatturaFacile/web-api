<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\GdoTradeTerms\Models\GdoAccrualRun;

/**
 * A monthly accrual run of the GDO rebates and contributions was posted.
 */
final readonly class AccrualRunPosted
{
    use Dispatchable;

    public function __construct(public GdoAccrualRun $run) {}
}
