<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

/**
 * An application entering a stage.
 */
final class ApplicationStageEvent extends CandidateRecord
{
    protected $table = 'hr_application_stage_events';

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
