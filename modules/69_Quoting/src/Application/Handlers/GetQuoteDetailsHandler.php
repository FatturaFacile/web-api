<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Handlers;

use Modules\Quoting\Application\Queries\GetQuoteDetailsQuery;
use Modules\Quoting\Infrastructure\Models\CpqQuote;

final class GetQuoteDetailsHandler
{
    public function handle(GetQuoteDetailsQuery $query): CpqQuote
    {
        return CpqQuote::query()->with(['sections', 'lines', 'envelopes', 'auditEvents', 'comments'])->findOrFail($query->quoteId);
    }
}
