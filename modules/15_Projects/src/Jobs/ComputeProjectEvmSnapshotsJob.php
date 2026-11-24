<?php

declare(strict_types=1);

namespace Modules\Projects\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Projects\Commands\ComputeProjectEvmSnapshotCommand;
use Modules\Projects\Models\Project;
use Modules\Projects\Services\ProjectEvmCommandHandler;
use Throwable;

/**
 * Nightly earned value run of one tenant (Mod 082): every running project gets a fresh financial snapshot, which syncs the
 * cost ledger, rolls up PV, EV and AC per WBS node and raises the CPI, SPI and budget absorption alerts of Mod 008.
 * A failing project is reported and does not stop the others.
 */
final class ComputeProjectEvmSnapshotsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Statuses in which a project accumulates cost or progress. */
    public const array RunningStatuses = ['active', 'on_hold'];

    public function __construct()
    {
        $this->afterCommit();
    }

    public function handle(ProjectEvmCommandHandler $handler): void
    {
        Project::query()->whereIn('status', self::RunningStatuses)->orderBy('id')->each(function (Project $project) use ($handler): void {
            try {
                $handler->computeSnapshot(new ComputeProjectEvmSnapshotCommand($project->id, 'scheduled'));
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
