<?php

declare(strict_types=1);

namespace App\Services\Invoicing;

use App\Models\Invoice;
use App\Services\SwissQrBill\QrBillGeneratorService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly QrBillGeneratorService $qrBillGenerator
    ) {}

    /**
     * Create an invoice with items, calculating all tax totals, gross amounts and CHF 0.05 rounding.
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
            $totalTax = 0.0;

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
                $lineTax = round($lineSubtotal * ($taxRate / 100.0), 2);

                $totalNet += $lineSubtotal;
                $totalTax += $lineTax;

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

            $unroundedGross = $totalNet + $totalTax;
            $roundingAmount = 0.0;
            $totalGross = $unroundedGross;

            if ($isChf) {
                $totalGross = $this->qrBillGenerator->roundChfAmount($unroundedGross);
                $roundingAmount = $this->qrBillGenerator->computeRoundingDifference($unroundedGross);
            }

            $invoice = new Invoice;
            $invoice->tenant_id = $this->tenantContext->getTenantId();
            $invoice->fill($data);
            $invoice->total_net = $totalNet;
            $invoice->total_tax = $totalTax;
            $invoice->total_gross = $totalGross;
            $invoice->rounding_amount = $roundingAmount;
            $invoice->payment_status = 'unpaid';
            $invoice->save();

            foreach ($computedItems as $computedItem) {
                $invoice->items()->create($computedItem);
            }

            return $invoice->load(['items', 'contact']);
        });
    }
}
