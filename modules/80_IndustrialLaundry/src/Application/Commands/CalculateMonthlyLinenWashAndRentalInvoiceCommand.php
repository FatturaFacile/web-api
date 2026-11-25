<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Commands;

/**
 * Consolidates the monthly bill of a customer contract: wash (pieces or net kg), guaranteed minimums, nominal workwear rental and
 * the garments unreturned past the threshold, and drafts the invoice (TD24 in Italy, QR-bill in Switzerland).
 */
final readonly class CalculateMonthlyLinenWashAndRentalInvoiceCommand
{
    public function __construct(
        public string $tenantId,
        public ?string $userId,
        public string $contractId,
        public string $periodStart,
        public string $periodEnd,
        public bool $createInvoice = true,
    ) {}
}
