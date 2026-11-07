<?php

declare(strict_types=1);

namespace App\Services\Invoicing;

use App\Models\Invoice;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Sales\VatSummaryCalculator;
use App\Services\SwissQrBill\QrBillGeneratorService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Invoicing\Actions\PostInvoiceAction;
use Modules\SDI\Enums\TipoDocumento;

class InvoiceService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly QrBillGeneratorService $qrBillGenerator,
        private readonly DocumentNumberingService $numbering,
        private readonly PostInvoiceAction $postInvoice,
        private readonly VatSummaryCalculator $vat = new VatSummaryCalculator
    ) {}

    /**
     * Create an invoice with items, calculating all tax totals, gross amounts and CHF 0.05 rounding. In this flow the
     * creation is the emission: the number comes from the sequence of the year and series, and anything but a proforma
     * reaches the ledger in the same transaction, so a closed period leaves neither the document nor a gap.
     *
     * @param  array<string, mixed>  $data
     */
    public function createInvoice(array $data): Invoice
    {
        return DB::transaction(function () use ($data): Invoice {
            $currency = strtoupper((string) ($data['currency'] ?? 'EUR'));
            $isChf = ($currency === 'CHF');

            $itemsData = $data['items'] ?? [];
            unset($data['items']);

            $totalNet = 0.0;
            $vatEntries = [];

            $computedItems = [];
            foreach ($itemsData as $item) {
                $qty = (float) $item['quantity'];
                $price = (float) $item['unit_price'];
                $discount = (float) ($item['discount_percentage'] ?? 0);
                $taxRate = (float) $item['tax_rate'];

                $lineSubtotal = $qty * $price;
                if ($discount > 0) {
                    $lineSubtotal -= ($lineSubtotal * ($discount / 100.0));
                }
                $lineSubtotal = round($lineSubtotal, 2);

                $totalNet += $lineSubtotal;
                $vatEntries[] = ['rate' => $taxRate, 'taxable' => $lineSubtotal];

                $computedItems[] = [
                    'product_id' => $item['product_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'tax_rate' => $taxRate,
                    'discount_percentage' => $discount,
                    'line_total' => $lineSubtotal,
                ];
            }

            // VAT per rate on the summed net, like the summary of the electronic invoice
            $totalTax = (float) $this->vat->totalTax($vatEntries);
            $unroundedGross = $totalNet + $totalTax;
            $roundingAmount = 0.0;
            $totalGross = $unroundedGross;

            if ($isChf) {
                $totalGross = $this->qrBillGenerator->roundChfAmount($unroundedGross);
                $roundingAmount = $this->qrBillGenerator->computeRoundingDifference($unroundedGross);
            }

            unset($data['tenant_id'], $data['invoice_number']);
            $series = match ($data['invoice_type'] ?? 'standard') {
                'proforma' => 'proforma',
                'credit_note' => 'credit_note',
                default => TipoDocumento::tryFrom((string) ($data['document_type'] ?? ''))?->numberingSeries() ?? 'invoice',
            };
            $number = $this->numbering->next($series, (int) date('Y', strtotime((string) $data['date'])));

            $invoice = new Invoice;
            $invoice->fill($data);
            $invoice->invoice_number = $number['number'];
            $invoice->fiscal_year = $number['fiscal_year'];
            $invoice->sequential_number = $number['sequential_number'];
            $invoice->tenant_id = $this->tenantContext->getTenantId();
            $invoice->total_net = $totalNet;
            $invoice->total_tax = $totalTax;
            $invoice->total_gross = $totalGross;
            $invoice->rounding_amount = $roundingAmount;
            $invoice->payment_status = 'unpaid';
            $invoice->save();

            foreach ($computedItems as $computedItem) {
                $invoice->items()->create($computedItem);
            }

            if ($invoice->invoice_type !== 'proforma') {
                $this->postInvoice->issue((string) $invoice->id);
            }

            return $invoice->refresh()->load(['items', 'contact']);
        });
    }
}
