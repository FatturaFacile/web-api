<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Database\Factories\PipelineStageFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A step of the hiring pipeline. An outcome marks the stage that ends an application.
 */
#[UseFactory(PipelineStageFactory::class)]
final class PipelineStage extends CandidateRecord
{
    /** @use HasFactory<PipelineStageFactory> */
    use HasFactory;

    public const string OutcomeHired = 'hired';

    public const string OutcomeRejected = 'rejected';

    protected $table = 'hr_pipeline_stages';

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }
}
