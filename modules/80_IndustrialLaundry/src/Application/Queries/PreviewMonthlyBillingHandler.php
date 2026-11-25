<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

use Illuminate\Support\Carbon;
use Modules\IndustrialLaundry\Models\ParContract;
use Modules\IndustrialLaundry\Services\MonthlyBillingService;

final class PreviewMonthlyBillingHandler
{
    public function __construct(private readonly MonthlyBillingService $billing) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(PreviewMonthlyBillingQuery $query): array
    {
        $result = $this->billing->preview(ParContract::query()->with('lines')->findOrFail($query->contractId), Carbon::parse($query->periodStart), Carbon::parse($query->periodEnd));
        $result['lines'] = array_map(static fn (array $line): array => ['kind' => $line['kind']->value, 'article_type' => $line['article_type']?->value] + $line, $result['lines']);

        return $result;
    }
}
