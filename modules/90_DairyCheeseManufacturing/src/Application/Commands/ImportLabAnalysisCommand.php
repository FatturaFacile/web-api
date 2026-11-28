<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Commands;

use Modules\DairyCheeseManufacturing\Domain\Enums\LabFormat;

final readonly class ImportLabAnalysisCommand
{
    public function __construct(public string $content, public LabFormat $format, public bool $commit, public ?string $userId = null) {}
}
