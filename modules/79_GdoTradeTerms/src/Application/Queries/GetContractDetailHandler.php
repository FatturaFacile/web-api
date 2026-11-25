<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

use Modules\GdoTradeTerms\Models\GdoContract;

final class GetContractDetailHandler
{
    public function handle(GetContractDetailQuery $query): GdoContract
    {
        return GdoContract::query()->with(['contact:id,company_name,first_name,last_name,country', 'scales.tiers', 'promotions'])->findOrFail($query->contractId);
    }
}
