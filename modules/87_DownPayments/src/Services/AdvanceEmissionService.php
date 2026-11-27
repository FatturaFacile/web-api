<?php

declare(strict_types=1);

namespace Modules\DownPayments\Services;

use App\Actions\Sales\RecordPayment;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\DownPayments\Domain\Enums\CalculationMode;
use Modules\DownPayments\Domain\Enums\InstallmentStatus;
use Modules\DownPayments\Domain\Enums\LinkStatus;
use Modules\DownPayments\Domain\Enums\LinkType;
use Modules\DownPayments\Events\AdvanceInvoiceIssued;
use Modules\DownPayments\Events\ProformaConverted;
use Modules\DownPayments\Exceptions\DownPaymentException;
use Modules\DownPayments\Models\DownPaymentChain;
use Modules\DownPayments\Models\DownPaymentInstallment;
use Modules\DownPayments\Models\DownPaymentLink;
use Modules\DownPayments\Models\DownPaymentLinkVatLine;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * Issues the VAT-bearing documents of a chain: the advance invoice (TD02 in Italy, Akontorechnung in Switzerland) and the
 * pro-forma advance request, which posts nothing until the customer pays and is then converted into the TD02 dated on the payment
 * day (Art. 6 par. 4 DPR 633/72, Art. 40 LIVA). Every document goes through the invoicing service, so numbering, VAT, the ledger
 * posting and the events are those of any other invoice; the per-VAT-rate ledger of the chain follows.
 *
 * @phpstan-type Slice array{vat_rate: string, net: string, vat: string, gross: string}
 */
final class AdvanceEmissionService
{
    public function __construct(
        private readonly InvoicingServiceInterface $invoicing,
        private readonly InstallmentPlanService $plan,
        private readonly ChainLedger $ledger,
        private readonly RecordPayment $payments,
    ) {}

    /**
     * The split of an installment over the VAT rates still open of the chain.
     *
     * @return list<Slice>
     *
     * @throws DownPaymentException when the installment no longer fits in what is left of the source
     */
    public function slicesFor(DownPaymentChain $chain, DownPaymentInstallment $installment): array
    {
        $open = array_map(static fn (array $row): string => $row['net'], $this->ledger->residualByRate($chain));
        $mode = CalculationMode::from($installment->calculation_mode);
        $amount = match ($mode) {
            CalculationMode::FixedNet => (string) $installment->amount_net,
            CalculationMode::FixedGross => (string) $installment->amount_gross,
            CalculationMode::Percentage => null,
        };

        return $this->plan->advanceRow($mode, $installment->percentage !== null ? (string) $installment->percentage : null, $amount, (string) $chain->total_contract_net, $open, (int) $installment->sequence)['vat_breakdown'];
    }

    /**
     * Issues the advance invoice of a set of VAT slices and records it in the chain.
     *
     * @param  list<Slice>  $slices
     * @param  array{date?: ?string, due_date?: ?string, label?: ?string, payment_method?: ?string}  $options
     */
    public function issueAdvance(DownPaymentChain $chain, ?DownPaymentInstallment $installment, array $slices, array $options, string $userId, ?DownPaymentLink $proforma = null): DownPaymentLink
    {
        return DB::transaction(function () use ($chain, $installment, $slices, $options, $userId, $proforma): DownPaymentLink {
            $invoice = $this->invoicing->issueInvoice($this->invoiceData($chain, $options, $userId, false, $proforma?->document_id), $this->items($chain, $slices, $options['label'] ?? $installment?->milestone_label), $userId);
            $link = $this->recordLink($chain, $installment, LinkType::AdvanceInvoiceTd02, $invoice, $slices, LinkStatus::Issued, $proforma);

            if ($installment !== null) {
                $installment->forceFill(['status' => InstallmentStatus::Invoiced->value, 'linked_invoice_id' => (string) $invoice->id])->save();
            }

            $this->ledger->refresh($chain);
            AdvanceInvoiceIssued::dispatch((string) $chain->id, (string) $link->id, (string) $invoice->id, (string) $chain->tenant_id);

            return $link;
        });
    }

    /**
     * Issues the pro-forma advance request of a set of VAT slices: no VAT and no revenue are posted, it only reserves what it asks
     * for and opens a receivable expectation until the payment comes.
     *
     * @param  list<Slice>  $slices
     * @param  array{date?: ?string, due_date?: ?string, label?: ?string}  $options
     */
    public function requestProforma(DownPaymentChain $chain, ?DownPaymentInstallment $installment, array $slices, array $options, string $userId): DownPaymentLink
    {
        return DB::transaction(function () use ($chain, $installment, $slices, $options, $userId): DownPaymentLink {
            $proforma = $this->invoicing->createInvoice($this->invoiceData($chain, $options, $userId, true, null), $this->items($chain, $slices, $options['label'] ?? $installment?->milestone_label));
            $link = $this->recordLink($chain, $installment, LinkType::ProformaAdvanceRequest, $proforma, $slices, LinkStatus::Open, null);

            if ($installment !== null) {
                $installment->forceFill(['status' => InstallmentStatus::Requested->value, 'linked_document_id' => (string) $proforma->id])->save();
            }

            $this->ledger->refresh($chain);

            return $link;
        });
    }

    /**
     * Converts the open pro-forma request into the official advance invoice dated on the payment day and records the collection
     * against it. Safe to call twice: a request already converted returns the invoice it produced.
     *
     * @param  string  $paidAt  the payment date (Y-m-d)
     *
     * @throws DownPaymentException when the link is not an open pro-forma request
     */
    public function convertProforma(DownPaymentChain $chain, DownPaymentLink $link, string $paidAt, string $userId, ?string $reference = null, ?string $paymentMethod = null): DownPaymentLink
    {
        return DB::transaction(function () use ($chain, $link, $paidAt, $userId, $reference, $paymentMethod): DownPaymentLink {
            $locked = DownPaymentLink::query()->where('tenant_id', $chain->tenant_id)->whereKey($link->id)->lockForUpdate()->firstOrFail();

            if ($locked->link_type !== LinkType::ProformaAdvanceRequest->value) {
                throw new DownPaymentException('link_not_a_proforma');
            }

            if ($locked->status === LinkStatus::Converted->value) {
                return DownPaymentLink::query()->where('parent_link_id', $locked->id)->where('link_type', LinkType::AdvanceInvoiceTd02->value)->firstOrFail();
            }

            if ($locked->status !== LinkStatus::Open->value) {
                throw new DownPaymentException('proforma_not_open');
            }

            $slices = $locked->vatLines->map(static fn (DownPaymentLinkVatLine $line): array => [
                'vat_rate' => Decimal::round2((string) $line->vat_percentage),
                'net' => (string) $line->net_amount,
                'vat' => (string) $line->vat_amount,
                'gross' => (string) $line->gross_amount,
            ])->values()->all();
            $installment = $locked->installment_id !== null ? DownPaymentInstallment::query()->whereKey($locked->installment_id)->first() : null;

            // The request stops reserving the amount in the same moment the invoice takes it over
            $locked->forceFill(['status' => LinkStatus::Converted->value])->save();
            $advance = $this->issueAdvance($chain, $installment, $slices, ['date' => $paidAt, 'due_date' => $paidAt, 'label' => $installment?->milestone_label], $userId, $locked);
            $invoice = Invoice::withoutGlobalScopes()->where('tenant_id', $chain->tenant_id)->whereKey($advance->document_id)->firstOrFail();

            $this->payments->execute($invoice, [
                'amount' => (string) $advance->gross_amount,
                'paid_at' => $paidAt,
                'payment_method' => $paymentMethod,
                'reference' => $reference ?? 'DPC-'.$locked->id,
                'notes' => 'Down payment '.$chain->chain_number,
                'unique_reference' => true,
            ]);

            if ($installment !== null) {
                $installment->forceFill(['status' => InstallmentStatus::Converted->value])->save();
            }

            ProformaConverted::dispatch((string) $chain->id, (string) $locked->id, (string) $advance->id, (string) $chain->tenant_id);

            return $advance->refresh();
        });
    }

    /**
     * The open pro-forma request of a proforma document, if the chain has one.
     */
    public function openProformaOf(string $tenantId, string $documentId): ?DownPaymentLink
    {
        return DownPaymentLink::query()
            ->where('tenant_id', $tenantId)
            ->where('document_id', $documentId)
            ->where('link_type', LinkType::ProformaAdvanceRequest->value)
            ->where('status', LinkStatus::Open->value)
            ->first();
    }

    /**
     * Converts the pro-forma requests paid by a Treasury collection: the collection names the proforma, and the whole amount asked
     * for must have come in. A partial collection is left to the accountant and logged.
     */
    public function convertCollected(string $tenantId, string $proformaId, float $collected, string $paidAt, string $reference, string $userId = 'system'): ?DownPaymentLink
    {
        $link = $this->openProformaOf($tenantId, $proformaId);

        if ($link === null) {
            return null;
        }

        if (Decimal::compare(Decimal::round2(number_format($collected, 2, '.', '')), (string) $link->gross_amount) < 0) {
            Log::warning('Collection smaller than the pro-forma advance request: not converted.', ['link_id' => $link->id, 'collected' => $collected]);

            return null;
        }

        $chain = DownPaymentChain::query()->where('tenant_id', $tenantId)->whereKey($link->chain_id)->firstOrFail();

        return $this->convertProforma($chain, $link, CarbonImmutable::parse($paidAt)->toDateString(), $userId, $reference);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function invoiceData(DownPaymentChain $chain, array $options, string $userId, bool $proforma, ?string $proformaId): array
    {
        return array_filter([
            'tenant_id' => (string) $chain->tenant_id,
            'contact_id' => (string) $chain->customer_id,
            'sales_order_id' => $chain->sales_order_id,
            'quote_id' => $chain->source_type === 'quote' ? (string) $chain->source_id : null,
            'proforma_id' => $proformaId,
            'currency' => (string) $chain->currency,
            'jurisdiction' => (string) $chain->jurisdiction,
            'invoice_type' => $proforma ? 'proforma' : 'standard',
            'is_deposit' => true,
            'skip_charge_lines' => true,
            'date' => $options['date'] ?? null,
            'due_date' => $options['due_date'] ?? null,
            'payment_method' => $options['payment_method'] ?? null,
            'notes' => 'Down payment chain '.$chain->chain_number,
            'created_by' => Str::isUuid($userId) ? $userId : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * One line per VAT rate, so every rate keeps its own tax and the final invoice can deduct each of them.
     *
     * @param  list<Slice>  $slices
     * @return list<array<string, mixed>>
     */
    private function items(DownPaymentChain $chain, array $slices, ?string $label): array
    {
        $items = [];

        foreach ($slices as $slice) {
            $items[] = [
                'description' => (string) __('down_payments.advance_line', ['label' => $label !== null && $label !== '' ? $label : $chain->chain_number, 'source' => (string) $chain->source_number]),
                'quantity' => '1',
                'unit_price' => $slice['net'],
                'tax_rate' => $slice['vat_rate'],
                'discount_percentage' => '0',
            ];
        }

        return $items;
    }

    /**
     * @param  list<Slice>  $slices
     */
    private function recordLink(DownPaymentChain $chain, ?DownPaymentInstallment $installment, LinkType $type, SalesInvoice $document, array $slices, LinkStatus $status, ?DownPaymentLink $parent): DownPaymentLink
    {
        $net = array_reduce($slices, static fn (string $sum, array $slice): string => Decimal::add($sum, $slice['net']), '0');
        $vat = array_reduce($slices, static fn (string $sum, array $slice): string => Decimal::add($sum, $slice['vat']), '0');
        $link = DownPaymentLink::query()->create([
            'tenant_id' => (string) $chain->tenant_id,
            'chain_id' => (string) $chain->id,
            'source_type' => (string) $chain->source_type,
            'source_id' => (string) $chain->source_id,
            'installment_id' => $installment !== null ? (string) $installment->id : null,
            'link_type' => $type->value,
            'document_id' => (string) $document->id,
            'document_number' => (string) $document->invoice_number,
            'document_date' => CarbonImmutable::parse($document->date)->toDateString(),
            'parent_link_id' => $parent !== null ? (string) $parent->id : null,
            'status' => $status->value,
            'net_amount' => Decimal::round2($net),
            'vat_amount' => Decimal::round2($vat),
            'gross_amount' => Decimal::round2(Decimal::add($net, $vat)),
        ]);

        foreach ($slices as $slice) {
            $line = new DownPaymentLinkVatLine([
                'tenant_id' => (string) $chain->tenant_id,
                'down_payment_link_id' => (string) $link->id,
                'vat_rate_id' => null,
                'vat_code' => ($chain->jurisdiction === 'CH' ? 'MWST ' : 'IVA ').rtrim(rtrim($slice['vat_rate'], '0'), '.').'%',
                'vat_percentage' => $slice['vat_rate'],
                'net_amount' => $slice['net'],
                'vat_amount' => $slice['vat'],
                'gross_amount' => $slice['gross'],
                'credited_net_amount' => '0.00', 'credited_vat_amount' => '0.00', 'credited_gross_amount' => '0.00',
                'settled_net_amount' => '0.00', 'settled_vat_amount' => '0.00', 'settled_gross_amount' => '0.00',
            ]);
            $line->refreshResidual();
            $line->save();
        }

        return $link->load('vatLines');
    }

    /**
     * Keeps a tenant scope out of the way for a document the chain refers to.
     */
    public function invoiceOf(DownPaymentLink $link): ?SalesInvoice
    {
        return $link->document_id === null ? null : SalesInvoice::withoutGlobalScope(TenantScope::class)->where('tenant_id', $link->tenant_id)->whereKey($link->document_id)->first();
    }
}
