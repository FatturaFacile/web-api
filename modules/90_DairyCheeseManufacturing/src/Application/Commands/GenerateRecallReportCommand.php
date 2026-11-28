<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Commands;

final readonly class GenerateRecallReportCommand
{
    public function __construct(public string $type, public ?string $id, public ?string $serial, public string $reason, public bool $apply, public ?string $userId = null) {}
}
