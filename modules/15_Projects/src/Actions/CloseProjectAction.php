<?php

declare(strict_types=1);

namespace Modules\Projects\Actions;

use Modules\Projects\Models\Project;
use Modules\Projects\Services\ProjectClosingService;

/**
 * Closes a project after its closing checks (see ProjectClosingService).
 */
final readonly class CloseProjectAction
{
    public function __construct(private ProjectClosingService $closing) {}

    public function execute(Project $project, string $userId, ?string $reason = null): Project
    {
        return $this->closing->close($project, $userId, $reason);
    }
}
