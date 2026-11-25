<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Commands;

final readonly class RemoveExpenseLineCommand
{
    public function __construct(
        public string $reportId,
        public string $lineId,
        public string $userId,
    ) {}
}
