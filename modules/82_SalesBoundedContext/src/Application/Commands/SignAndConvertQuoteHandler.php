<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\SalesBoundedContext\Domain\Enums\ElectronicSignatureStandard;
use Modules\SalesBoundedContext\Domain\ValueObjects\DecimalMoney;
use Modules\SalesBoundedContext\Events\QuoteConvertedToCommercialDocumentEvent;
use Modules\SalesBoundedContext\Events\QuoteElectronicallySignedEvent;
use Modules\SalesBoundedContext\Exceptions\SalesBoundedContextException;
use Modules\SalesBoundedContext\Models\SbcCpqQuote;
use Modules\SalesBoundedContext\Models\SbcDdtInvoice;
use Modules\SalesBoundedContext\Models\SbcFrameworkOrder;

final readonly class SignAndConvertQuoteHandler
{
    public function handle(SignAndConvertQuoteCommand $command): SbcCpqQuote
    {
        return match ($command->action) {
            'create' => $this->createOrRevise($command->tenantId, $command->payload, null),
            'revise' => $this->createOrRevise($command->tenantId, $command->payload, $command->quoteId),
            'sign' => $this->sign($command->tenantId, (string) $command->quoteId, $command->payload),
            'convert_to_order' => $this->convert($command->tenantId, (string) $command->quoteId, 'sales_order'),
            'convert_to_invoice' => $this->convert($command->tenantId, (string) $command->quoteId, 'invoice'),
            default => throw SalesBoundedContextException::invalidStateTransition("Unsupported CPQ quote action [{$command->action}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createOrRevise(string $tenantId, array $data, ?string $parentQuoteId): SbcCpqQuote
    {
        $currency = strtoupper((string) ($data['currency'] ?? 'CHF'));
        $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];

        $parent = $parentQuoteId !== null
            ? SbcCpqQuote::query()->where('tenant_id', $tenantId)->findOrFail($parentQuoteId)
            : null;

        $netTotal = DecimalMoney::of(0, $currency);
        $taxTotal = DecimalMoney::of(0, $currency);

        foreach ($lines as $line) {
            $isOptional = (bool) ($line['is_optional'] ?? false);
            $isSelected = (bool) ($line['is_selected'] ?? true);
            if ($isOptional && ! $isSelected) {
                continue;
            }
            $qty = (float) ($line['quantity'] ?? 1);
            $unitPrice = DecimalMoney::of((float) ($line['unit_price'] ?? 0), $currency);
            $discount = (float) ($line['discount_pct'] ?? 0);
            $taxRate = (float) ($line['tax_rate'] ?? 8.1);

            $lineNet = $unitPrice->multiply($qty)->applyCascadingDiscounts([$discount]);
            $lineTax = $lineNet->percentage($taxRate);
            $netTotal = $netTotal->add($lineNet);
            $taxTotal = $taxTotal->add($lineTax);
        }

        $grossTotal = $netTotal->add($taxTotal);

        return DB::transaction(function () use ($tenantId, $data, $parent, $currency, $lines, $netTotal, $taxTotal, $grossTotal): SbcCpqQuote {
            $quoteNumber = $parent !== null ? $parent->quote_number : (string) ($data['quote_number'] ?? 'CPQ-'.strtoupper(Str::random(6)));
            $version = $parent !== null
                ? ((int) SbcCpqQuote::query()->where('tenant_id', $tenantId)->where('quote_number', $quoteNumber)->max('version_number')) + 1
                : 1;

            return SbcCpqQuote::create([
                'tenant_id' => $tenantId,
                'parent_quote_id' => $parent?->id,
                'dossier_id' => $data['dossier_id'] ?? $parent?->dossier_id,
                'opportunity_id' => $data['opportunity_id'] ?? $parent?->opportunity_id,
                'quote_number' => $quoteNumber,
                'version_number' => $version,
                'title' => (string) ($data['title'] ?? $parent?->title ?? 'CPQ Commercial Proposal'),
                'valid_until' => $data['valid_until'] ?? now()->addDays(30)->toDateString(),
                'currency' => $currency,
                'payment_terms_code' => (string) ($data['payment_terms_code'] ?? 'NET_30'),
                'lines' => $lines,
                'total_net' => $netTotal->roundHalfUp(),
                'total_tax' => $taxTotal->roundHalfUp(),
                'total_gross' => $grossTotal->roundHalfUp(),
                'signature_standard' => ElectronicSignatureStandard::from((string) ($data['signature_standard'] ?? 'zertes_qes')),
                'signature_status' => 'pending',
                'signature_audit_trail' => [],
                'status' => 'issued',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sign(string $tenantId, string $quoteId, array $data): SbcCpqQuote
    {
        $quote = SbcCpqQuote::query()->where('tenant_id', $tenantId)->findOrFail($quoteId);
        $standard = ElectronicSignatureStandard::from((string) ($data['signature_standard'] ?? $quote->signature_standard?->value ?? 'zertes_qes'));
        $docHash = hash('sha256', $quote->quote_number.'|v'.$quote->version_number.'|'.$quote->total_gross.'|'.json_encode($quote->lines));

        $trail = is_array($quote->signature_audit_trail) ? $quote->signature_audit_trail : [];
        $trail[] = [
            'standard' => $standard->value,
            'signer_name' => (string) ($data['signer_name'] ?? 'Authorized Signatory'),
            'signer_email' => (string) ($data['signer_email'] ?? 'signer@example.ch'),
            'ip_address' => (string) ($data['ip_address'] ?? '127.0.0.1'),
            'document_sha256' => $docHash,
            'certificate_serial' => (string) ($data['certificate_serial'] ?? 'ZERTES-'.strtoupper(Str::random(10))),
            'signed_at' => now()->toIso8601String(),
        ];

        $quote->fill([
            'signature_standard' => $standard,
            'signature_status' => 'signed',
            'signature_audit_trail' => $trail,
            'status' => 'accepted',
        ]);
        $quote->save();

        QuoteElectronicallySignedEvent::dispatch($tenantId, (string) $quote->id, $standard->value, $docHash);

        return $quote;
    }

    private function convert(string $tenantId, string $quoteId, string $targetKind): SbcCpqQuote
    {
        $quote = SbcCpqQuote::query()->where('tenant_id', $tenantId)->findOrFail($quoteId);
        if ($quote->signature_status !== 'signed') {
            throw SalesBoundedContextException::invalidStateTransition('Quote must be electronically signed (eIDAS/ZertES) before 1-click conversion.');
        }

        $targetId = DB::transaction(function () use ($tenantId, $quote, $targetKind): string {
            if ($targetKind === 'sales_order') {
                $order = SbcFrameworkOrder::create([
                    'tenant_id' => $tenantId,
                    'dossier_id' => $quote->dossier_id,
                    'quote_id' => $quote->id,
                    'order_number' => 'SO-'.strtoupper(Str::random(6)),
                    'order_kind' => 'sales_order',
                    'currency' => $quote->currency,
                    'total_amount' => $quote->total_gross,
                    'fulfillment_status' => 'reserved',
                    'lines' => $quote->lines,
                ]);
                $quote->converted_order_id = $order->id;
                $quote->status = 'converted';
                $quote->save();

                return (string) $order->id;
            }

            $invoice = SbcDdtInvoice::create([
                'tenant_id' => $tenantId,
                'dossier_id' => $quote->dossier_id,
                'document_kind' => 'immediate_invoice',
                'document_number' => 'INV-'.strtoupper(Str::random(6)),
                'sdi_doc_type' => $quote->currency === 'EUR' ? 'TD01' : null,
                'currency' => $quote->currency,
                'total_net' => $quote->total_net,
                'total_tax' => $quote->total_tax,
                'total_gross' => $quote->total_gross,
                'issue_date' => now()->toDateString(),
                'status' => 'issued',
            ]);
            $quote->converted_invoice_id = $invoice->id;
            $quote->status = 'converted';
            $quote->save();

            return (string) $invoice->id;
        });

        QuoteConvertedToCommercialDocumentEvent::dispatch($tenantId, (string) $quote->id, $targetKind, $targetId);

        return $quote;
    }
}
