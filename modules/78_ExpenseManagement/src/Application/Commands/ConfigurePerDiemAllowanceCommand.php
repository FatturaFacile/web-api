<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Commands;

final readonly class ConfigurePerDiemAllowanceCommand
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $reportId,
        public array $data,
        public string $userId,
    ) {}
}
