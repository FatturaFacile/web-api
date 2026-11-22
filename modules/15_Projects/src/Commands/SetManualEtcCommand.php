<?php

declare(strict_types=1);

namespace Modules\Projects\Commands;

/**
 * Sets (or, with a null estimate, clears) the manual bottom-up estimate to complete of a project or WBS node. The
 * snapshot is recomputed afterwards.
 */
final readonly class SetManualEtcCommand
{
    public function __construct(
        public string $projectId,
        public ?string $wbsNodeId,
        public ?string $estimateToComplete,
        public ?string $reason = null,
        public ?string $userId = null,
    ) {}
}
