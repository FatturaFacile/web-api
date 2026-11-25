<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\StatutoryFinancials\Models\StatutoryDossier;

final class GetDossiersHandler
{
    /**
     * @return LengthAwarePaginator<int, StatutoryDossier>
     */
    public function handle(GetDossiersQuery $query): LengthAwarePaginator
    {
        return StatutoryDossier::query()
            ->with(['fiscalYear:id,year_code,start_date,end_date', 'package'])
            ->when($query->status !== null, fn ($rows) => $rows->where('status', $query->status))
            ->when($query->fiscalYearId !== null, fn ($rows) => $rows->where('fiscal_year_id', $query->fiscalYearId))
            ->when($query->standard !== null, fn ($rows) => $rows->where('accounting_standard', $query->standard))
            ->orderByDesc('created_at')
            ->paginate($query->perPage);
    }
}
