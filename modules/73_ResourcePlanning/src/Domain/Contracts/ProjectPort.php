<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Domain\Contracts;

use Modules\ResourcePlanning\Domain\ValueObjects\ProjectRef;
use Modules\ResourcePlanning\Domain\ValueObjects\WbsRef;

/**
 * Projects and their work breakdown structure (Mod 078).
 */
interface ProjectPort
{
    public function project(string $projectId): ?ProjectRef;

    public function wbsNode(string $wbsNodeId): ?WbsRef;

    /**
     * @param  list<string>  $projectIds
     * @return array<string, string> project name by id
     */
    public function projectNames(array $projectIds): array;

    /**
     * @param  list<string>  $wbsNodeIds
     * @return array<string, string> node title by id
     */
    public function wbsTitles(array $wbsNodeIds): array;
}
