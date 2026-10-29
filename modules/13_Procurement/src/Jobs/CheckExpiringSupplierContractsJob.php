<?php

declare(strict_types=1);

namespace Modules\Procurement\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Procurement\Models\SupplierContract;

final class CheckExpiringSupplierContractsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public readonly ?string $tenantId = null
    ) {}

    public function handle(): void
    {
        $threshold = Carbon::now()->addDays(60);

        $query = SupplierContract::query()
            ->where('status', 'active')
            ->where('ends_at', '<=', $threshold);

        if ($this->tenantId !== null) {
            $query->where('tenant_id', $this->tenantId);
        }

        $query->chunkById(100, function ($contracts) {
            foreach ($contracts as $contract) {
                if (Carbon::now()->isAfter($contract->ends_at)) {
                    $contract->update(['status' => 'expired']);
                }
            }
        });
    }
}
