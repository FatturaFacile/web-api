<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Commands;

final readonly class ExecuteCareBatchCommand
{
    public function __construct(public string $careType, public string $scope, public string $targetId, public ?string $performedBy, public ?string $notes) {}
}
