<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

use Illuminate\Support\Str;
use Modules\SalesBoundedContext\Domain\ValueObjects\DecimalMoney;
use Modules\SalesBoundedContext\Domain\ValueObjects\FiscalIdentity;
use Modules\SalesBoundedContext\Events\DeferredSummaryInvoiceIssuedEvent;
use Modules\SalesBoundedContext\Models\SbcDdtInvoice;

final readonly class IssueDdtAndDeferredInvoiceHandler
{
    public function handle(IssueDdtAndDeferredInvoiceCommand $command): SbcDdtInvoice
    {
        $data = $command->payload;
        $kind = (string) ($data['document_kind'] ?? 'ddt_it');
        $currency = strtoupper((string) ($data['currency'] ?? ($kind === 'lieferschein_ch' ? 'CHF' : 'EUR')));

        $groupedDdtNumbers = is_array($data['grouped_ddt_numbers'] ?? null) ? $data['grouped_ddt_numbers'] : [];
        $net = DecimalMoney::of((float) ($data['total_net'] ?? 0), $currency);
        $taxRate = (float) ($data['tax_rate'] ?? ($currency === 'CHF' ? 8.1 : 22.0));
        $tax = isset($data['total_tax'])
            ? DecimalMoney::of((float) $data['total_tax'], $currency)
            : $net->percentage($taxRate);
        $gross = $net->add($tax);

        // If summary deferred invoicing from existing DDTs and total_net wasn't explicitly passed, sum them
        if ($kind === 'deferred_summary_invoice' && $groupedDdtNumbers !== [] && ! isset($data['total_net'])) {
            $ddts = SbcDdtInvoice::query()
                ->where('tenant_id', $command->tenantId)
                ->whereIn('document_number', $groupedDdtNumbers)
                ->get();
            $sumNet = DecimalMoney::of(0, $currency);
            $sumTax = DecimalMoney::of(0, $currency);
            foreach ($ddts as $ddt) {
                $sumNet = $sumNet->add(DecimalMoney::of((float) $ddt->total_net, $currency));
                $sumTax = $sumTax->add(DecimalMoney::of((float) $ddt->total_tax, $currency));
                $ddt->status = 'invoiced_deferred';
                $ddt->save();
            }
            $net = $sumNet;
            $tax = $sumTax;
            $gross = $net->add($tax);
        }

        $sdiDocType = match ($kind) {
            'deferred_summary_invoice' => 'TD24',
            'credit_note' => 'TD04',
            'debit_note' => 'TD05',
            'immediate_invoice' => 'TD01',
            default => null,
        };

        $prefix = match ($kind) {
            'lieferschein_ch' => 'LS-CH-',
            'deferred_summary_invoice' => 'FD-TD24-',
            'credit_note' => 'NC-TD04-',
            'debit_note' => 'ND-TD05-',
            'immediate_invoice' => 'FT-',
            default => 'DDT-',
        };

        $qrRef = $currency === 'CHF'
            ? FiscalIdentity::buildSwissQrReference((string) random_int(10000000, 99999999))
            : null;

        $doc = SbcDdtInvoice::create([
            'tenant_id' => $command->tenantId,
            'dossier_id' => $data['dossier_id'] ?? null,
            'sales_order_id' => $data['sales_order_id'] ?? null,
            'related_document_id' => $data['related_document_id'] ?? null,
            'document_kind' => $kind,
            'document_number' => (string) ($data['document_number'] ?? $prefix.strtoupper(Str::random(6))),
            'sdi_doc_type' => $sdiDocType,
            'qr_bill_reference' => $qrRef,
            'grouped_ddt_numbers' => $groupedDdtNumbers,
            'is_drop_shipping' => (bool) ($data['is_drop_shipping'] ?? false),
            'drop_ship_supplier_name' => $data['drop_ship_supplier_name'] ?? null,
            'currency' => $currency,
            'total_net' => $net->roundHalfUp(),
            'total_tax' => $tax->roundHalfUp(),
            'total_gross' => $gross->roundHalfUp(),
            'issue_date' => $data['issue_date'] ?? now()->toDateString(),
            'status' => 'issued',
        ]);

        DeferredSummaryInvoiceIssuedEvent::dispatch(
            $command->tenantId,
            (string) $doc->id,
            $kind,
            $doc->document_number,
            $gross->roundHalfUp(),
        );

        return $doc;
    }
}
