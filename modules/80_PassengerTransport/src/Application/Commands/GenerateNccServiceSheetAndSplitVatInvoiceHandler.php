<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Application\Commands;

use Illuminate\Support\Facades\DB;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\PassengerTransport\Domain\Enums\SheetStatus;
use Modules\PassengerTransport\Models\PassengerBooking;
use Modules\PassengerTransport\Models\ServiceSheet;
use Modules\PassengerTransport\Services\BookingInvoicingService;
use Modules\PassengerTransport\Services\ServiceSheetService;

/**
 * Opens (or completes) the NCC electronic service sheet of a booking, closes it with its signature and PDF, transmits it to the
 * register and, once the trip is completed, produces the invoice with the VAT split of the territories.
 */
final class GenerateNccServiceSheetAndSplitVatInvoiceHandler
{
    public function __construct(private readonly ServiceSheetService $sheets, private readonly BookingInvoicingService $invoicing) {}

    /**
     * @return array{sheet: ServiceSheet, invoice: SalesInvoice|null}
     */
    public function handle(GenerateNccServiceSheetAndSplitVatInvoiceCommand $command): array
    {
        $booking = PassengerBooking::query()->findOrFail($command->bookingId);

        return DB::transaction(function () use ($command, $booking): array {
            $sheet = ServiceSheet::query()->where('booking_id', $booking->id)->where('status', SheetStatus::Open->value)->first();
            $sheet = $sheet === null
                ? $this->sheets->open($booking, $command->sheetData, $command->userId)
                : $this->sheets->update($sheet, $command->sheetData, $command->userId);

            if ($command->closeSheet || $command->transmit) {
                $sheet = $this->sheets->close($sheet, $command->userId);
            }

            if ($command->transmit) {
                $sheet = $this->sheets->transmit($sheet, $command->userId);
            }

            return ['sheet' => $sheet, 'invoice' => $command->invoice ? $this->invoicing->invoiceBooking($booking->refresh(), $command->userId) : null];
        });
    }
}
