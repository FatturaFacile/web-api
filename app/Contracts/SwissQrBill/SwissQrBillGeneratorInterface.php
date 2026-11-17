<?php

declare(strict_types=1);

namespace App\Contracts\SwissQrBill;

use App\Models\Invoice;
use App\Services\SwissQrBill\GeneratedQrBill;
use App\Services\SwissQrBill\QrBillPdfOptions;

interface SwissQrBillGeneratorInterface
{
    /**
     * Build the validated SIX SPC 0200 QR-bill of an invoice.
     */
    public function generate(Invoice $invoice): GeneratedQrBill;

    /**
     * Render the payment part (and receipt) as PDF bytes: attached to an A4 page or as a standalone 210x105 mm slip.
     */
    public function generatePdf(Invoice $invoice, QrBillPdfOptions $options): string;
}
