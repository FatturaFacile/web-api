<?php

declare(strict_types=1);

namespace App\Services\Invoicing;

use App\Models\Invoice;
use App\Services\Invoicing\Tax\FiscalInputResolver;
use App\Services\Invoicing\Tax\FiscalResult;
use App\Services\Invoicing\Tax\ItalianFiscalCalculator;
use App\Services\SwissQrBill\QrBillGeneratorService;
use App\Services\Tenancy\TenantContext;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    /**
     * Payload keys that drive the fiscal calculation but are not invoice columns.
     */
    private const FISCAL_PAYLOAD_KEYS = ['fund', 'withholding', 'enasarco', 'stamp_duty_charged_to_customer'];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly QrBillGeneratorService $qrBillGenerator,
        private readonly ItalianFiscalCalculator $fiscalCalculator,
        private readonly FiscalInputResolver $fiscalInputResolver,
    ) {}

    /**
     * Create an invoice with items. Italian invoices are computed by the integer-cent fiscal calculator (cassa, VAT
     * summary, ritenuta, ENASARCO, bollo, split payment) and its result is persisted; Swiss (CHF / CH) invoices keep
     * the per-line calculation with CHF 0.05 rounding.
     *
     * @param  array<string, mixed>  $data
     */
    public function createInvoice(array $data): Invoice
    {
        $currency = strtoupper((string) ($data['currency'] ?? 'EUR'));
        $isSwiss = $currency === 'CHF' || strtoupper((string) ($data['jurisdiction'] ?? 'IT')) === 'CH';

        return $isSwiss ? $this->createSwissInvoice($data, $currency === 'CHF') : $this->createItalianInvoice($data);
    }

    /**
     * Fiscal figures for an unsaved Italian invoice payload (used by the preview endpoint and invoice creation).
     *
     * @param  array<string, mixed>  $data
     */
    public function previewItalianFiscal(array $data): FiscalResult
    {
        return $this->fiscalCalculator->calculate($this->fiscalInputResolver->resolve($data, $this->tenantContext->getTenant()));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createItalianInvoice(array $data): Invoice
    {
        return DB::transaction(function () use ($data): Invoice {
            // Resolved inside the transaction so the ENASARCO year-to-date read is consistent with the insert.
            $result = $this->previewItalianFiscal($data);

            $itemsData = array_values((array) ($data['items'] ?? []));
            unset($data['items'], $data['tenant_id']);

            foreach (self::FISCAL_PAYLOAD_KEYS as $key) {
                unset($data[$key]);
            }

            $invoice = new Invoice;
            $invoice->fill($data);
            $invoice->tenant_id = $this->tenantContext->getTenantId();
            $invoice->fill($this->fiscalAttributes($result));
            $invoice->payment_status = 'unpaid';
            $invoice->save();

            foreach ($itemsData as $index => $item) {
                $line = $result->lines[$index];

                $invoice->items()->create([
                    'product_id' => $item['product_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => (string) $item['quantity'],
                    'unit_price' => (string) $item['unit_price'],
                    'tax_rate' => Money::fromScaled($line['rate'], 2),
                    'discount_percentage' => (string) ($item['discount_percentage'] ?? 0),
                    'line_total' => Money::fromCents($line['net']),
                    'natura' => $line['natura'],
                    'withholding_applies' => (bool) ($item['withholding_applies'] ?? true),
                    'fund_applies' => (bool) ($item['fund_applies'] ?? true),
                ]);
            }

            return $invoice->load(['items', 'contact']);
        });
    }

    /**
     * Invoice columns holding the calculator result.
     *
     * @return array<string, mixed>
     */
    private function fiscalAttributes(FiscalResult $result): array
    {
        $input = $result->input;
        $fund = $input->fund;
        $withholding = $input->withholding;
        $enasarco = $input->enasarco;

        return [
            'total_net' => Money::fromCents($result->net),
            'total_tax' => Money::fromCents($result->vat),
            'total_gross' => Money::fromCents($result->gross),
            'total_due' => Money::fromCents($result->due),
            'rounding_amount' => '0.00',
            'split_payment' => $input->splitPayment,
            'reverse_charge' => $input->reverseCharge,
            'vat_payability' => $input->splitPayment ? 'S' : (in_array($input->vatPayability, ['I', 'D'], true) ? $input->vatPayability : 'I'),

            'fund_enabled' => $fund !== null,
            'fund_type' => $fund?->type,
            'fund_percent' => $fund !== null ? Money::fromScaled(Money::toScaled($fund->percent, 2), 2) : null,
            'fund_amount' => $fund !== null ? Money::fromCents($result->fund) : null,
            'fund_vat_rate' => $fund !== null ? Money::fromScaled($result->fundRate, 2) : null,
            'fund_natura' => $fund !== null ? $result->fundNatura : null,
            'fund_withholding_applies' => $fund?->withholdingApplies ?? false,

            'withholding_tax_enabled' => $withholding !== null,
            'withholding_tax_type' => $withholding?->type,
            'withholding_tax_reason' => $withholding?->reason,
            'withholding_tax_percent' => $withholding !== null ? Money::fromScaled(Money::toScaled($withholding->rate, 2), 2) : null,
            'withholding_tax_taxable_percent' => $withholding !== null ? Money::fromScaled(Money::toScaled($withholding->taxablePercent, 2), 2) : null,
            'withholding_tax_base' => $withholding !== null ? Money::fromCents($result->withholdingBase) : null,
            'withholding_tax_amount' => $withholding !== null ? Money::fromCents($result->withholding) : null,
            'withholding_tax_includes_fund' => $withholding !== null && $result->withholdingIncludesFund(),

            'enasarco_percent' => $enasarco !== null ? Money::fromScaled(Money::toScaled($enasarco->rate, 2), 2) : null,
            'enasarco_commissions' => $enasarco !== null ? Money::fromCents($result->enasarcoCommissions) : null,
            'enasarco_taxable' => $enasarco !== null ? Money::fromCents($result->enasarcoTaxable) : null,
            'enasarco_amount' => $enasarco !== null ? Money::fromCents($result->enasarco) : null,

            'stamp_duty_applied' => $result->stampDutyApplied,
            'stamp_duty_amount' => $result->stampDutyApplied ? Money::fromCents($result->stampDuty) : null,
            'stamp_duty_charged_to_customer' => $result->stampDutyChargedToCustomer,

            'legal_notices' => $result->legalNotices,
            'vat_summary' => $result->vatSummaryArray(),
        ];
    }

    /**
     * Swiss invoices: per-line VAT and CHF 0.05 rounding (behaviour unchanged by the Italian fiscal calculator).
     *
     * @param  array<string, mixed>  $data
     */
    private function createSwissInvoice(array $data, bool $isChf): Invoice
    {
        return DB::transaction(function () use ($data, $isChf): Invoice {
            $itemsData = $data['items'] ?? [];
            unset($data['items']);

            foreach (self::FISCAL_PAYLOAD_KEYS as $key) {
                unset($data[$key]);
            }

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
}
