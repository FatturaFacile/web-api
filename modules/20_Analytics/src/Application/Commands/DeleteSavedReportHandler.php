<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Needs the right to manage the report; the report and its schedules go away together.
 */
final readonly class DeleteSavedReportHandler
{
    public function handle(DeleteSavedReportCommand $command): void
    {
        Gate::authorize('manage', $command->report);

        DB::transaction(function () use ($command): void {
            $command->report->schedules()->delete();
            $command->report->delete();
        });
    }
}
