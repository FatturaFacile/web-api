<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

use Modules\GdoTradeTerms\Models\GdoAccrualRun;

final class GetAccrualRunDetailHandler
{
    public function handle(GetAccrualRunDetailQuery $query): GdoAccrualRun
    {
        return GdoAccrualRun::query()->with('lines.contract:id,code,name')->findOrFail($query->runId);
    }
}
