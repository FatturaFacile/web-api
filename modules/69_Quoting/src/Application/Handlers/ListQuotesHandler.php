<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Handlers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Quoting\Application\Queries\ListQuotesQuery;
use Modules\Quoting\Infrastructure\Models\CpqQuote;

final class ListQuotesHandler
{
    /**
     * @return LengthAwarePaginator<int, CpqQuote>
     */
    public function handle(ListQuotesQuery $query): LengthAwarePaginator
    {
        return CpqQuote::query()
            ->when($query->status !== null, fn ($q) => $q->where('status', $query->status))
            ->when($query->contactId !== null, fn ($q) => $q->where('contact_id', $query->contactId))
            ->when($query->salesRepId !== null, fn ($q) => $q->where('sales_rep_id', $query->salesRepId))
            ->when($query->expiringBefore !== null, fn ($q) => $q->whereDate('valid_until', '<=', $query->expiringBefore))
            ->when($query->rootQuoteId !== null, fn ($q) => $q->where('root_quote_id', $query->rootQuoteId))
            ->latest()
            ->paginate($query->perPage);
    }
}
