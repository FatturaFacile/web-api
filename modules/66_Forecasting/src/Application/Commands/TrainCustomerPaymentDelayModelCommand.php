<?php

declare(strict_types=1);

namespace Modules\Forecasting\Application\Commands;

/**
 * Learn how each customer of the tenant pays and refresh the expected collection dates of the open receivables.
 */
final readonly class TrainCustomerPaymentDelayModelCommand
{
    public function __construct(
        public string $tenantId,
        /** Weight half-life of a settled invoice, in days. */
        public int $halfLifeDays = 90,
        /** How far back the settled invoices are read, in months. */
        public int $lookbackMonths = 24,
    ) {}
}
