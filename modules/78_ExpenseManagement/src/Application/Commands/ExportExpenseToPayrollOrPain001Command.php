<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Commands;

use Modules\ExpenseManagement\Domain\Enums\PayoutChannel;

final readonly class ExportExpenseToPayrollOrPain001Command
{
    public function __construct(
        public string $reportId,
        public PayoutChannel $channel,
        public string $userId,
        public ?string $executionDate = null,
    ) {}
}
