<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Domain\ValueObjects;

/**
 * A node of the work breakdown structure of a project (Mod 078).
 */
final readonly class WbsRef
{
    public function __construct(public string $id, public string $projectId, public string $title) {}
}
