<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

use Modules\GdoTradeTerms\Models\GdoSettlement;

final class GetSettlementDetailHandler
{
    public function handle(GetSettlementDetailQuery $query): GdoSettlement
    {
        return GdoSettlement::query()->with(['contract:id,code,name,currency,jurisdiction,tolerance_percent', 'lines'])->findOrFail($query->settlementId);
    }
}
