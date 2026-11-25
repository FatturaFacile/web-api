<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Application\Commands;

final readonly class GenerateNccServiceSheetAndSplitVatInvoiceCommand
{
    /**
     * @param  array<string, mixed>  $sheetData  times, kilometres and passenger manifest of the sheet
     */
    public function __construct(
        public string $bookingId,
        public array $sheetData,
        public bool $closeSheet,
        public bool $transmit,
        public bool $invoice,
        public ?string $userId,
    ) {}
}
