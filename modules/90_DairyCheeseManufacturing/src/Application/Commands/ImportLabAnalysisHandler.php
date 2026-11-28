<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Commands;

use Modules\DairyCheeseManufacturing\Services\QualityTestService;

final class ImportLabAnalysisHandler
{
    public function __construct(private readonly QualityTestService $tests) {}

    /**
     * @return array{rows: list<array<string, mixed>>, summary: array{total: int, valid: int, anomalous: int, rejected: int}, committed: bool}
     */
    public function handle(ImportLabAnalysisCommand $command): array
    {
        return $this->tests->import($command->content, $command->format, $command->commit, $command->userId);
    }
}
