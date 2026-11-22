<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Manufacturing\Services\MesSync\MaterialiseShiftInstancesAction;

/**
 * Materialises the UTC shift instances of one tenant over the configured horizon (scheduled daily, so the horizon
 * keeps moving forward).
 */
final class MaterialiseShiftInstancesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $tenantId)
    {
        $this->afterCommit();
    }

    /**
     * Runs the materialisation.
     */
    public function handle(MaterialiseShiftInstancesAction $materialise): void
    {
        $materialise($this->tenantId);
    }
}
