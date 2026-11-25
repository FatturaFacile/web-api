<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\GdoTradeTerms\Models\GdoSettlement;

/**
 * Every line of a GDO settlement run was settled (credit note issued, promotional invoice reconciled).
 */
final readonly class SettlementSettled
{
    use Dispatchable;

    public function __construct(public GdoSettlement $settlement) {}
}
