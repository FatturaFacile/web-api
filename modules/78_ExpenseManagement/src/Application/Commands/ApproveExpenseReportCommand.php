<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Commands;

final readonly class ApproveExpenseReportCommand
{
    public function __construct(
        public string $reportId,
        public string $userId,
        public ?string $approvedAmount = null,
        public ?string $comment = null,
    ) {}
}
