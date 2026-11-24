<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Domain\ValueObjects;

/**
 * A project of Mod 078 as the planner sees it.
 */
final readonly class ProjectRef
{
    public function __construct(public string $id, public string $name, public string $status, public bool $allocatable) {}
}
