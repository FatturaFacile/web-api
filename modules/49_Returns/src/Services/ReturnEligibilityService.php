<?php

declare(strict_types=1);

namespace Modules\Returns\Services;

use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Modules\Returns\Models\ReturnReasonCode;
use Modules\Returns\Models\ReturnsSetting;
use Modules\Returns\Support\Decimal;
use Modules\Returns\Support\ReturnsError;

/**
 * Evaluates return and warranty eligibility against an originating sales invoice or sales delivery note (DDT),
 * computes restocking fees for commercial/non-defect returns, and generates RMA barcodes & shipping labels.
 */
final readonly class ReturnEligibilityService
{
    /** @var list<string> */
    private const array ZeroFeeReasonCodes = [
        'defective',
        'damaged_in_transit',
        'wrong_item',
        'wrong_shipment',
        'doa',
        'warranty_defect',
    ];

    public function __construct(
        private ReturnsSettingsService $settings,
    ) {}

    /**
     * Evaluate eligibility and restocking fee for a return request.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function evaluate(string $tenantId, array $data): array
    {
        $setting = $this->settings->settings($tenantId);
        $contactId = (string) $data['contact_id'];
        $reasonCode = (string) $data['reason_code'];
        $requestedOn = Carbon::parse((string) ($data['requested_on'] ?? now()->toDateString()))->startOfDay();

        $invoice = ! empty($data['invoice_id'])
            ? Invoice::query()->with('items')->where('tenant_id', $tenantId)->whereKey((string) $data['invoice_id'])->first()
            : null;
        $deliveryNote = ! empty($data['delivery_note_id'])
            ? DeliveryNote::query()->with('items')->where('tenant_id', $tenantId)->whereKey((string) $data['delivery_note_id'])->first()
            : null;

        if ($invoice === null && $deliveryNote === null) {
            throw ReturnsError::on('invoice_id', 'rma.source_required');
        }

        if ($invoice !== null && ((string) $invoice->contact_id !== $contactId || in_array($invoice->invoice_type, ['credit_note', 'proforma'], true))) {
            throw ReturnsError::on('invoice_id', 'rma.invoice_not_returnable');
        }

        if ($deliveryNote !== null && (string) $deliveryNote->contact_id !== $contactId) {
            throw ReturnsError::on('delivery_note_id', 'rma.order_not_returnable');
        }

        $sourceDate = Carbon::parse((string) ($invoice->date ?? $deliveryNote?->date))->startOfDay();
        $daysSincePurchase = (int) $sourceDate->diffInDays($requestedOn, false);
        $daysSincePurchase = max(0, $daysSincePurchase);

        $returnWindowDays = $setting->return_window_days ?: ReturnsSetting::DefaultWindowDays;
        $warrantyWindowDays = $setting->warranty_window_days ?: ReturnsSetting::DefaultWarrantyWindowDays;

        $withinReturnWindow = $daysSincePurchase <= $returnWindowDays;
        $withinWarrantyWindow = $daysSincePurchase <= $warrantyWindowDays;

        $reason = ReturnReasonCode::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $reasonCode)
            ->first();

        $isDefect = $reason->is_defect ?? in_array($reasonCode, ['defective', 'damaged_in_transit', 'doa', 'warranty_defect'], true);
        $isZeroFee = $isDefect || in_array($reasonCode, self::ZeroFeeReasonCodes, true);

        $grossAmount = '0';
        $evaluatedLines = [];

        foreach (($data['lines'] ?? []) as $index => $line) {
            $qty = Decimal::round($line['quantity'] ?? 1, 4);
            $unitPrice = '0';
            $description = (string) ($line['description'] ?? 'Item');
            $productId = (string) ($line['product_id'] ?? '');
            $taxRate = '22.00';

            if (! empty($line['invoice_item_id']) && $invoice !== null) {
                /** @var InvoiceItem|null $invItem */
                $invItem = $invoice->items->firstWhere('id', (string) $line['invoice_item_id']);
                if ($invItem !== null) {
                    $factor = Decimal::sub('1', Decimal::div($invItem->discount_percentage ?? '0', '100'));
                    $unitPrice = Decimal::round(Decimal::mul($invItem->unit_price, $factor), 4);
                    $description = (string) $invItem->description;
                    $productId = (string) $invItem->product_id;
                    $taxRate = Decimal::round($invItem->tax_rate ?? '22.00', 2);
                }
            } elseif (! empty($line['delivery_note_item_id']) && $deliveryNote !== null) {
                /** @var DeliveryNoteItem|null $ddtItem */
                $ddtItem = $deliveryNote->items->firstWhere('id', (string) $line['delivery_note_item_id']);
                if ($ddtItem !== null) {
                    $unitPrice = Decimal::round($ddtItem->unit_price ?? '0', 4);
                    $description = (string) $ddtItem->description;
                    $productId = (string) $ddtItem->product_id;
                    $taxRate = Decimal::round($ddtItem->tax_rate ?? '22.00', 2);
                }
            } elseif ($productId !== '') {
                $product = Product::query()->where('tenant_id', $tenantId)->whereKey($productId)->first();
                if ($product !== null) {
                    $unitPrice = Decimal::round($product->sale_price ?? '0', 4);
                    $description = (string) $product->name;
                    $taxRate = Decimal::round($product->tax_rate ?? '22.00', 2);
                }
            }

            $lineTotal = Decimal::round(Decimal::mul($qty, $unitPrice), 2);
            $grossAmount = Decimal::add($grossAmount, $lineTotal);

            $evaluatedLines[] = [
                'index' => $index,
                'product_id' => $productId,
                'description' => $description,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'tax_rate' => $taxRate,
                'line_total' => $lineTotal,
            ];
        }

        $restockingFeeApplicable = ! $isZeroFee && Decimal::isPositive($grossAmount);
        $restockingFeePercent = $restockingFeeApplicable
            ? Decimal::round($data['restocking_fee_percent'] ?? $setting->default_restocking_fee_percent ?? ReturnsSetting::DefaultRestockingFeePercent, 2)
            : '0.00';

        $restockingFeeAmount = $restockingFeeApplicable
            ? Decimal::round(Decimal::mul($grossAmount, Decimal::div($restockingFeePercent, '100')), 2)
            : '0.00';
        $netRefundableAmount = Decimal::round(max('0', Decimal::sub($grossAmount, $restockingFeeAmount)), 2);

        $eligible = $isDefect ? $withinWarrantyWindow : $withinReturnWindow;

        return [
            'eligible' => $eligible,
            'within_return_window' => $withinReturnWindow,
            'within_warranty_window' => $withinWarrantyWindow,
            'return_window_days' => $returnWindowDays,
            'warranty_window_days' => $warrantyWindowDays,
            'days_since_purchase' => $daysSincePurchase,
            'source_date' => $sourceDate->toDateString(),
            'is_defect_reason' => $isDefect,
            'restocking_fee_applicable' => $restockingFeeApplicable,
            'restocking_fee_percent' => $restockingFeePercent,
            'restocking_fee_amount' => $restockingFeeAmount,
            'gross_return_amount' => Decimal::round($grossAmount, 2),
            'net_refundable_amount' => $netRefundableAmount,
            'lines' => $evaluatedLines,
        ];
    }

    /**
     * Generate a deterministic RMA barcode & return shipping label payload.
     *
     * @return array<string, mixed>
     */
    public function buildShippingLabel(string $rmaNumber, string $barcode, ?string $carrier, ?string $trackingNumber): array
    {
        return [
            'rma_number' => $rmaNumber,
            'rma_barcode' => $barcode,
            'barcode_format' => 'CODE128',
            'carrier' => $carrier ?: 'DHL Express Reverse',
            'tracking_number' => $trackingNumber ?: ('TRK-'.$rmaNumber),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function generateBarcode(string $rmaNumber): string
    {
        return 'RMA-BC-'.strtoupper(str_replace('-', '', $rmaNumber)).'-'.substr(strtoupper(sha1($rmaNumber)), 0, 6);
    }
}
