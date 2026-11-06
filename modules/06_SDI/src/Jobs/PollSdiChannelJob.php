<?php

declare(strict_types=1);

namespace Modules\SDI\Jobs;

use App\Models\Tenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\SDI\Services\SdiInboundProcessor;

/**
 * Reads the SDI channel of one tenant. The tenant travels by id: the processor restores its context inside the worker.
 */
final class PollSdiChannelJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $tenantId) {}

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    public function handle(SdiInboundProcessor $processor): void
    {
        $tenant = Tenant::find($this->tenantId);

        if ($tenant !== null && ! $tenant->is_maintenance) {
            $processor->poll($tenant);
        }
    }
}
