<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Services;

use App\Models\Invoice;
use App\Services\Pdf\DocumentPdfService;

/**
 * The courtesy PDF of an eBill: the Swiss QR-bill invoice the debtor can read next to the structured data, rendered by the
 * standard invoice PDF pipeline (the QR payment part included) in the language of the customer.
 */
final class EbillCourtesyPdfService
{
    public function __construct(private readonly DocumentPdfService $pdfs) {}

    /**
     * @return array{content: string, filename: string, sha256: string, size: int}
     */
    public function render(Invoice $invoice): array
    {
        $rendered = $this->pdfs->render($invoice, ['locale' => $invoice->contact?->document_language ?: null]);

        return [
            'content' => $rendered->content,
            'filename' => $rendered->filename !== '' ? $rendered->filename : 'invoice-'.$invoice->invoice_number.'.pdf',
            'sha256' => hash('sha256', $rendered->content),
            'size' => strlen($rendered->content),
        ];
    }
}
