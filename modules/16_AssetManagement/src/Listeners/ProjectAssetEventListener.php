<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Listeners;

use Modules\AssetManagement\Contracts\ProjectAssetIntegrationServiceInterface;
use Modules\Projects\Events\ProjectClosureReadyEvent;
use Modules\Projects\Events\ProjectCreatedEvent;

final readonly class ProjectAssetEventListener
{
    public function __construct(
        private ProjectAssetIntegrationServiceInterface $projectAssetService
    ) {}

    public function handleProjectCreated(ProjectCreatedEvent $event): void
    {
        // Hook for automatic equipment reservation when project templates specify required machinery
    }

    public function handleProjectClosureReady(ProjectClosureReadyEvent $event): void
    {
        $this->projectAssetService->releaseProjectAssets($event->projectId);
    }
}
