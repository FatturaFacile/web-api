<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

use Illuminate\Support\Carbon;
use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Services\ContractProgressService;

final class GetContractProgressHandler
{
    public function __construct(private readonly ContractProgressService $progress) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetContractProgressQuery $query): array
    {
        return $this->progress->progress(GdoContract::query()->with(['scales.tiers', 'promotions'])->findOrFail($query->contractId), $query->asOf === null ? Carbon::today() : Carbon::parse($query->asOf));
    }
}
