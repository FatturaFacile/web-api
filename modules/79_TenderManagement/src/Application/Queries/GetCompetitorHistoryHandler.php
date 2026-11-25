<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Application\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\TenderManagement\Models\CompetitorBenchmark;
use Modules\TenderManagement\Services\BenchmarkService;

final class GetCompetitorHistoryHandler
{
    public function __construct(private readonly BenchmarkService $benchmarks) {}

    /**
     * @return LengthAwarePaginator<int, CompetitorBenchmark>
     */
    public function handle(GetCompetitorHistoryQuery $query): LengthAwarePaginator
    {
        return $this->benchmarks
            ->filtered($query->search, $query->contractingAuthorityName, $query->cpvCode, $query->jurisdiction, $query->winnersOnly)
            ->orderByDesc('award_date')
            ->orderBy('competitor_name')
            ->paginate($query->perPage);
    }
}
