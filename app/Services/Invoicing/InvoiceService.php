<?php

declare(strict_types=1);

namespace App\Services\Invoicing;

use App\Models\Invoice;
use App\Models\Product;
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
            $products = $this->loadProducts($itemsData);
            foreach ($itemsData as $item) {
                $product = isset($item['product_id']) ? ($products[$item['product_id']] ?? null) : null;
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
                    // Customs snapshot: copied once so later product edits never rewrite issued documents.
                    'taric_code' => $item['taric_code'] ?? $product?->taric_code,
                    'country_of_origin' => $item['country_of_origin'] ?? $product?->country_of_origin,
                    'origin_type' => $item['origin_type'] ?? $product?->origin_type?->value,
                    'made_in_label' => $item['made_in_label'] ?? $product?->made_in_label,
                ];
            }

            $unroundedGross = $totalNet + $totalTax;
            $roundingAmount = 0.0;
            $totalGross = $unroundedGross;

            if ($isChf) {
                $totalGross = $this->qrBillGenerator->roundChfAmount($unroundedGross);
                $roundingAmount = $this->qrBillGenerator->computeRoundingDifference($unroundedGross);
            }

            unset($data['tenant_id']);
            $invoice = new Invoice;
            $invoice->fill($data);
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

            return $invoice->load(['items', 'contact']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $itemsData
     * @return array<string, Product>
     */
    private function loadProducts(array $itemsData): array
    {
        $ids = array_values(array_unique(array_filter(array_column($itemsData, 'product_id'))));
        if ($ids === []) {
            return [];
        }

        return Product::query()->whereIn('id', $ids)->get()->keyBy('id')->all();
    }
}
