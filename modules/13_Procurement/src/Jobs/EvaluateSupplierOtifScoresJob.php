<?php

declare(strict_types=1);

namespace Modules\Procurement\Jobs;

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

    public function __construct(
        public readonly ?string $tenantId = null,
    ) {
        $this->afterCommit();
    }

    public function handle(SupplierEvaluationServiceInterface $evaluationService): void
    {
        $query = Supplier::query()->where('status', 'active');

        if ($this->tenantId !== null) {
            $query->where('tenant_id', $this->tenantId);
        }

        $query->chunkById(100, function ($suppliers) use ($evaluationService): void {
            foreach ($suppliers as $supplier) {
                $evaluationService->calculateOtifScore($supplier);
            }
        });
    }
}
