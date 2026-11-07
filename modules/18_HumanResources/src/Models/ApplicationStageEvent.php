<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Database\Factories\ApplicationStageEventFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * An application entering a stage.
 */
#[UseFactory(ApplicationStageEventFactory::class)]
final class ApplicationStageEvent extends CandidateRecord
{
    /** @use HasFactory<ApplicationStageEventFactory> */
    use HasFactory;

    protected $table = 'hr_application_stage_events';

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
