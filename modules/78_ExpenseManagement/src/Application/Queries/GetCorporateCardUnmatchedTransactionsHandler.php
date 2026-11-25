<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\ExpenseManagement\Models\CorporateCardTransaction;

final class GetCorporateCardUnmatchedTransactionsHandler
{
    /**
     * @return LengthAwarePaginator<int, CorporateCardTransaction>
     */
    public function handle(GetCorporateCardUnmatchedTransactionsQuery $query): LengthAwarePaginator
    {
        return CorporateCardTransaction::query()
            ->with(['account', 'lines.report:id,report_number'])
            ->when($query->status !== null && $query->status !== '', fn ($rows) => $rows->where('status', $query->status))
            ->when($query->cardAccountId !== null, fn ($rows) => $rows->where('card_account_id', $query->cardAccountId))
            ->orderByDesc('transaction_date')
            ->paginate($query->perPage);
    }
}
