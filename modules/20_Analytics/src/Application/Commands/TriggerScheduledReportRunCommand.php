<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Commands;

use App\Models\User;

/**
 * Runs a schedule now ("Run now"), outside its calendar.
 */
final readonly class TriggerScheduledReportRunCommand
{
    public function __construct(public User $user, public string $scheduleId) {}
}
