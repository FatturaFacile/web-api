<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService\Commands;

/**
 * Turns the billable part of a signed service report into a customer invoice.
 */
final readonly class ConvertServiceReportToInvoiceCommand
{
    public function __construct(
        public string $reportId,
        public string $taxRate,
        public ?string $dueDate = null,
        public ?string $revenueAccountId = null,
        public ?string $actorUserId = null,
    ) {}
}
