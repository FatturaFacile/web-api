<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Commands;

use Modules\DairyCheeseManufacturing\Domain\Enums\Authority;

final readonly class GenerateRegulatorySubmissionCommand
{
    public function __construct(public Authority $authority, public string $period, public ?string $format = null, public ?string $userId = null) {}
}
