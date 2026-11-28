<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Commands;

use Modules\DairyCheeseManufacturing\Models\RegulatorySubmission;
use Modules\DairyCheeseManufacturing\Services\Regulatory\RegulatoryService;

final class GenerateRegulatorySubmissionHandler
{
    public function __construct(private readonly RegulatoryService $regulatory) {}

    public function handle(GenerateRegulatorySubmissionCommand $command): RegulatorySubmission
    {
        return $this->regulatory->generate($command->authority, $command->period, $command->format, $command->userId);
    }
}
