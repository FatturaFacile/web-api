<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

/**
 * A step of the hiring pipeline. An outcome marks the stage that ends an application.
 */
final class PipelineStage extends CandidateRecord
{
    public const string OutcomeHired = 'hired';

    public const string OutcomeRejected = 'rejected';

    protected $table = 'hr_pipeline_stages';

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }
}
