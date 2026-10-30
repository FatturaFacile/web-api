<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use App\Contracts\PdfGenerator;
use App\Exceptions\QrBillGenerationException;
use App\Models\Contact;
use App\Models\ContactAddress;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use App\Rules\PngSignature;
use App\Services\SwissQrBill\SwissQrBillService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Turns sales documents into PDFs: normalises each document type into view data, renders it through the
 * {@see PdfGenerator} and names the file. Works without a tenant context, so queued jobs can use it.
 */
class DocumentPdfService
{
    public function __construct(
        private readonly PdfGenerator $generator,
        private readonly SwissQrBillService $qrBills,
    ) {}

    public function render(Model $document): RenderedPdf
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $document->getAttribute('tenant_id');
        $tenant = $document->relationLoaded('tenant') ? $document->getRelation('tenant') : ($tenantId ? Tenant::find($tenantId) : null);

        $renderCallback = function () use ($document): RenderedPdf {
            [$view, $data, $type, $number] = match (true) {
                $document instanceof Invoice => $this->invoice($document),
                $document instanceof Quote => $this->quote($document),
                $document instanceof SalesOrder => $this->salesOrder($document),
                $document instanceof DeliveryNote => $this->deliveryNote($document),
                $document instanceof WorkOrder => $this->workOrder($document),
                $document instanceof ServiceReport => $this->serviceReport($document),
                default => throw new InvalidArgumentException('Unsupported document class: '.$document::class),
            };

            return new RenderedPdf(
                $this->generator->render($view, $data),
                Str::studly($type).'-'.trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $number), '-').'.pdf'
            );
        };

        if ($tenant !== null && $tenantContext->getTenantId() !== $tenant->id) {
            return $tenantContext->runWithTenant($tenant, $renderCallback);
        }

        return $renderCallback();
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: string, 3: string}
     */
    private function invoice(Invoice $invoice): array
    {
        $invoice->loadMissing(['tenant', 'contact.addresses', 'items', 'qrBillData']);

        $type = match ($invoice->invoice_type) {
            'credit_note' => 'credit_note',
            'proforma' => 'proforma',
            default => 'invoice',
        };

        [$qr, $qrError] = $invoice->invoice_type === 'ch_qr_bill' ? $this->swissQrBill($invoice) : [null, null];

        return [$invoice->invoice_type === 'ch_qr_bill' ? 'documents.ch_qr_bill' : 'documents.invoice', array_merge($this->common($invoice, $invoice->contact, $type, $invoice->invoice_number), [
            'date' => $invoice->date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'currency' => $invoice->currency,
            'notes' => $invoice->notes,
            'lines' => $this->lines($invoice->items),
            'show_prices' => true,
            'totals' => [
                'net' => (float) $invoice->total_net,
                'tax' => (float) $invoice->total_tax,
                'gross' => (float) $invoice->total_gross,
                'rounding' => (float) $invoice->rounding_amount,
                'stamp_duty' => $invoice->stamp_duty_applied ? (float) $invoice->stamp_duty_amount : 0.0,
                'withholding_tax' => $invoice->withholding_tax_enabled ? (float) $invoice->withholding_tax_amount : 0.0,
                'paid' => (float) $invoice->total_paid,
                'balance' => round((float) $invoice->total_gross - (float) $invoice->total_paid, 2),
            ],
            'payment' => [
                'method' => $invoice->payment_method,
                'terms' => $invoice->payment_terms,
                'bank_name' => $invoice->bank_name,
                'iban' => $invoice->bank_iban ?? $invoice->tenant?->iban,
            ],
            'qr' => $qr,
            'qr_error' => $qrError,
        ]), $type, $invoice->invoice_number];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: string, 3: string}
     */
    private function quote(Quote $quote): array
    {
        $quote->loadMissing(['tenant', 'contact.addresses', 'items']);

        return ['documents.quote', array_merge($this->common($quote, $quote->contact, 'quote', $quote->quote_number), [
            'date' => $quote->date?->toDateString(),
            'valid_until' => $quote->valid_until?->toDateString(),
            'currency' => $quote->currency,
            'notes' => $quote->notes,
            'custom_text' => $quote->custom_text,
            'lines' => $this->lines($quote->items),
            'show_prices' => true,
            'totals' => ['net' => (float) $quote->total_net, 'tax' => (float) $quote->total_tax, 'gross' => (float) $quote->total_gross],
            'signature' => [
                'signed_by' => $quote->signed_by,
                'signed_at' => $quote->signed_at?->toDateString(),
                'image' => $quote->signature_data !== null ? $this->dataUri(PngSignature::decode($quote->signature_data)) : null,
            ],
        ]), 'quote', $quote->quote_number];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: string, 3: string}
     */
    private function salesOrder(SalesOrder $order): array
    {
        $order->loadMissing(['tenant', 'contact.addresses', 'items']);

        return ['documents.quote', array_merge($this->common($order, $order->contact, 'sales_order', $order->order_number), [
            'date' => $order->date?->toDateString(),
            'valid_until' => $order->due_date?->toDateString(),
            'currency' => $order->currency,
            'notes' => $order->notes,
            'custom_text' => null,
            'lines' => $this->lines($order->items),
            'show_prices' => true,
            'totals' => ['net' => (float) $order->total_net, 'tax' => (float) $order->total_tax, 'gross' => (float) $order->total_gross],
            'signature' => null,
        ]), 'sales_order', $order->order_number];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: string, 3: string}
     */
    private function deliveryNote(DeliveryNote $note): array
    {
        $note->loadMissing(['tenant', 'contact.addresses', 'items']);
        $lines = $this->lines($note->items);
        $showPrices = (bool) $note->show_prices;

        $net = round(array_sum(array_map(fn (array $line): float => $line['quantity'] * $line['unit_price'], $lines)), 2);
        $tax = round(array_sum(array_map(fn (array $line): float => $line['quantity'] * $line['unit_price'] * $line['tax_rate'] / 100, $lines)), 2);

        return ['documents.delivery-note', array_merge($this->common($note, $note->contact, 'delivery_note', $note->ddt_number), [
            'date' => $note->date?->toDateString(),
            'currency' => 'EUR',
            'notes' => $note->notes,
            'lines' => $lines,
            'show_prices' => $showPrices,
            'totals' => $showPrices ? ['net' => $net, 'tax' => $tax, 'gross' => round($net + $tax, 2)] : null,
            'shipping_address' => $note->shipping_address,
            'transport' => [
                'reason' => $note->transport_reason,
                'goods_appearance' => $note->goods_appearance,
                'packages_count' => $note->packages_count,
                'gross_weight_kg' => $note->gross_weight_kg !== null ? (float) $note->gross_weight_kg : null,
                'carrier' => $note->carrier_name,
                'courier' => $note->courier,
                'license_plate' => $note->carrier_license_plate,
                'departure_time' => $note->departure_time?->format('Y-m-d H:i'),
            ],
        ]), 'delivery_note', $note->ddt_number];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: string, 3: string}
     */
    private function workOrder(WorkOrder $workOrder): array
    {
        $workOrder->loadMissing(['tenant', 'client.addresses', 'lines']);
        $lines = $this->lines($workOrder->lines);
        $net = round(array_sum(array_column($lines, 'line_total')), 2);
        $tax = round(array_sum(array_map(fn (array $line): float => $line['line_total'] * $line['tax_rate'] / 100, $lines)), 2);

        $signatureImage = null;

        $disk = (string) config('filesystems.private_disk', config('filesystems.default', 'local'));
        if ($workOrder->client_signature_path !== null && Storage::disk($disk)->exists($workOrder->client_signature_path)) {
            $signatureImage = $this->dataUri(Storage::disk($disk)->get($workOrder->client_signature_path));
        }

        return ['documents.work-order', array_merge($this->common($workOrder, $workOrder->client, 'work_order', $workOrder->work_order_number), [
            'date' => $workOrder->date?->toDateString(),
            'currency' => 'EUR',
            'notes' => $workOrder->notes,
            'lines' => $lines,
            'show_prices' => true,
            'totals' => ['net' => $net, 'tax' => $tax, 'gross' => round($net + $tax, 2)],
            'technician' => $workOrder->technician_user_id !== null ? User::find($workOrder->technician_user_id)?->name : null,
            'intervention_address' => $workOrder->intervention_address,
            'started_at' => $workOrder->started_at?->format('Y-m-d H:i'),
            'ended_at' => $workOrder->ended_at?->format('Y-m-d H:i'),
            'labor_hours' => (float) $workOrder->labor_hours,
            'signature' => [
                'signed_by' => $workOrder->client_signed_by,
                'signed_at' => $workOrder->signed_at?->toDateString(),
                'image' => $signatureImage,
            ],
        ]), 'work_order', $workOrder->work_order_number];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: string, 3: string}
     */
    private function serviceReport(ServiceReport $report): array
    {
        $report->loadMissing(['tenant', 'contact.addresses', 'lines.lineType', 'technician', 'signatures']);
        $lines = $this->lines($report->lines);

        $signature = $report->signatures->firstWhere('signer_type', 'customer') ?? $report->signatures->first();
        $signatureImage = null;
        $disk = config('filesystems.private_disk', config('filesystems.default', 'local'));

        if ($signature && Storage::disk($disk)->exists($signature->signature_path)) {
            $signatureImage = $this->dataUri(Storage::disk($disk)->get($signature->signature_path));
        }

        $currency = $report->contact?->currency ?? $report->tenant?->default_currency ?? 'EUR';

        return ['documents.service-report', array_merge($this->common($report, $report->contact, 'service_report', $report->report_number), [
            'date' => $report->report_date?->toDateString(),
            'report_date' => $report->report_date?->toDateString(),
            'currency' => $currency,
            'notes' => $report->customer_notes,
            'customer_notes' => $report->customer_notes,
            'lines' => $lines,
            'show_prices' => true,
            'totals' => ['net' => (float) $report->total_net, 'tax' => (float) $report->total_tax, 'gross' => (float) $report->total_gross],
            'technician' => $report->technician?->name,
            'site_address' => $report->site_address,
            'time_start' => $report->time_start,
            'time_end' => $report->time_end,
            'total_hours' => (float) $report->total_hours,
            'travel_distance_km' => (float) $report->travel_distance_km,
            'signature' => [
                'signer_name' => $signature?->signer_name,
                'signer_role' => $signature?->signer_role,
                'signed_at' => $signature?->signed_at?->toDateTimeString(),
                'signature_hash' => $signature?->signature_hash,
                'image' => $signatureImage,
            ],
        ]), 'service_report', $report->report_number];
    }

    /**
     * Build the Swiss QR-bill for an invoice. A paid invoice needs no payment slip; any other failure is logged and
     * reported to the template, so the rest of the document still renders.
     *
     * @return array{0: ?array<string, mixed>, 1: ?string} the slip data and the failure reason
     */
    private function swissQrBill(Invoice $invoice): array
    {
        try {
            return [$this->qrBills->generate($invoice)->toViewArray(), null];
        } catch (QrBillGenerationException $e) {
            if ($e->reason === QrBillGenerationException::INVALID_AMOUNT) {
                return [null, null];
            }

            Log::warning('Swiss QR-bill could not be generated for invoice {number}: {reason}', [
                'invoice_id' => $invoice->id,
                'number' => $invoice->invoice_number,
                'reason' => $e->reason,
                'violations' => $e->violations,
            ]);

            return [null, $e->reason];
        }
    }

    /**
     * Data shared by every template: issuer, customer and billing address.
     *
     * @return array<string, mixed>
     */
    private function common(Model $document, ?Contact $contact, string $type, string $number): array
    {
        $address = $contact?->addresses->firstWhere('type', 'billing') ?? $contact?->addresses->first();

        return [
            'type' => $type,
            'number' => $number,
            'tenant' => $document->tenant,
            'tenant_address_lines' => $this->tenantAddressLines($document->tenant?->settings['preferences']['company'] ?? []),
            'contact' => $contact,
            'contact_name' => $contact?->company_name ?: ($contact !== null ? trim($contact->first_name.' '.$contact->last_name) : null),
            'address_lines' => $address instanceof ContactAddress ? $this->addressLines($address) : [],
        ];
    }

    /**
     * Issuer address lines from the `company` tenant preferences (structured fields or two combined lines).
     *
     * @param  array<string, mixed>  $company
     * @return array<int, string>
     */
    private function tenantAddressLines(array $company): array
    {
        $lines = isset($company['street'])
            ? [trim($company['street'].' '.($company['house_number'] ?? '')), trim(($company['postal_code'] ?? '').' '.($company['city'] ?? ''))]
            : [(string) ($company['address_line_1'] ?? ''), (string) ($company['address_line_2'] ?? '')];

        return array_values(array_filter(array_map('trim', $lines)));
    }

    /**
     * @return array<int, string>
     */
    private function addressLines(ContactAddress $address): array
    {
        return array_values(array_filter([
            trim($address->street.' '.$address->house_number),
            trim($address->postal_code.' '.$address->city.($address->state_canton ? ' ('.$address->state_canton.')' : '')),
            $address->country,
        ]));
    }

    /**
     * @param  iterable<int, Model>  $items
     * @return array<int, array<string, mixed>>
     */
    private function lines(iterable $items): array
    {
        $lines = [];

        foreach ($items as $item) {
            $quantity = (float) $item->quantity;
            $unitPrice = (float) ($item->unit_price ?? 0);

            $lines[] = [
                'type' => $item->getAttribute('type'),
                'description' => $item->description,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_rate' => (float) ($item->tax_rate ?? 0),
                'discount_percentage' => (float) ($item->getAttribute('discount_percentage') ?? 0),
                'line_total' => $item->getAttribute('line_total') !== null ? (float) $item->line_total : round($quantity * $unitPrice, 2),
            ];
        }

        return $lines;
    }

    private function dataUri(?string $png): ?string
    {
        return $png !== null && $png !== '' ? 'data:image/png;base64,'.base64_encode($png) : null;
    }
}
