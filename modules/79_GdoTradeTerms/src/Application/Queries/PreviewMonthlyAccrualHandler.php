<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

use Illuminate\Support\Carbon;
use Modules\GdoTradeTerms\Services\AccrualService;

final class PreviewMonthlyAccrualHandler
{
    public function __construct(private readonly AccrualService $accruals) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(PreviewMonthlyAccrualQuery $query): array
    {
        return $this->accruals->run($query->tenantId, Carbon::parse($query->periodEnd), false, true, null);
    }
}
