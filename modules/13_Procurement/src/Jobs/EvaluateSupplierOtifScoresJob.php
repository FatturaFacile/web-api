<?php

declare(strict_types=1);

namespace Modules\Procurement\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Procurement\Contracts\SupplierEvaluationServiceInterface;
use Modules\Procurement\Models\Supplier;

final class EvaluateSupplierOtifScoresJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public readonly ?string $tenantId = null
    ) {}

    public function handle(SupplierEvaluationServiceInterface $evaluationService): void
    {
        $query = Supplier::query()->where('is_approved_vendor', true);
        if ($this->tenantId !== null) {
            $query->where('tenant_id', $this->tenantId);
        }

        $now = Carbon::now();
        $year = $now->year;
        $quarter = $now->quarter;

        $query->chunkById(100, function ($suppliers) use ($evaluationService, $year, $quarter) {
            foreach ($suppliers as $supplier) {
                $evaluationService->generateQuarterlyScorecard($supplier, $year, $quarter);
            }
        });
    }
}
