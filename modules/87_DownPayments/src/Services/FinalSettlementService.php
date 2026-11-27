<?php

declare(strict_types=1);

namespace Modules\DownPayments\Services;

use App\Services\Sales\DocumentTotalsCalculator;
use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\DownPayments\Domain\Enums\DepositResolution;
use Modules\DownPayments\Domain\Enums\DepositStatus;
use Modules\DownPayments\Domain\Enums\DepositType;
use Modules\DownPayments\Domain\Enums\LinkStatus;
use Modules\DownPayments\Domain\Enums\LinkType;
use Modules\DownPayments\Events\FinalSettlementGenerated;
use Modules\DownPayments\Exceptions\DownPaymentException;
use Modules\DownPayments\Models\DownPaymentChain;
use Modules\DownPayments\Models\DownPaymentLink;
use Modules\DownPayments\Models\DownPaymentLinkVatLine;
use Modules\DownPayments\Models\EarnestAndSecurityDeposit;
use Modules\DownPayments\Models\FinalSettlementDeductionLine;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesInvoiceItem;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesQuote;
use Modules\Invoicing\Models\SalesQuoteItem;
use Modules\Invoicing\Services\SalesBillingLines;

/**
 * The final settlement invoice of a chain (TD01, or TD24 when it is deferred): the whole supply at its own VAT rates, with one
 * negative deduction line per advance invoice and VAT rate for what is still open of it. The taxable of the final invoice is the
 * taxable of the supply less the taxable of the advances, and the same holds for the VAT, so the customer advances close with no
 * suspense balance. The advance invoices are referenced in the XML (DatiFattureCollegate).
 *
 * @phpstan-type DeductionRow array{link_id: string, advance_item_id: string, advance_invoice_number: string, advance_invoice_date: string, vat_rate: string, net: string, vat: string, gross: string}
 */
final class FinalSettlementService
{
    public function __construct(
        private readonly InvoicingServiceInterface $invoicing,
        private readonly SalesBillingLines $billingLines,
        private readonly ChainLedger $ledger,
        private readonly DepositService $deposits,
        private readonly DocumentTotalsCalculator $totals,
    ) {}

    /**
     * What the final invoice will state, with the references of the XML and the closing entry of the customer advances.
     *
     * @return array<string, mixed>
     *
     * @throws DownPaymentException when the chain cannot be settled
     */
    public function preview(DownPaymentChain $chain): array
    {
        $this->assertSettleable($chain);
        $supply = $this->supplyLines($chain);
        $deductions = $this->deductions($chain);
        $totals = $this->totals->calculateDecimal($this->itemsOf($supply, $deductions));

        return $this->describe($chain, $supply, $deductions, $totals);
    }

    /**
     * Issues the final settlement invoice and closes the chain. Held earnest money listed in `impute_deposit_ids` is imputed to the
     * price first, so the advance it becomes is deducted by this very invoice.
     *
     * @param  array{date?: ?string, due_date?: ?string, document_type?: ?string, payment_method?: ?string, impute_deposit_ids?: list<string>}  $options
     *
     * @throws DownPaymentException when the chain still has open pro-forma requests or unresolved earnest money
     */
    public function generate(DownPaymentChain $chain, array $options, string $userId): SalesInvoice
    {
        return DB::transaction(function () use ($chain, $options, $userId): SalesInvoice {
            $locked = DownPaymentChain::query()->where('tenant_id', $chain->tenant_id)->whereKey($chain->id)->lockForUpdate()->firstOrFail();
            $this->assertSettleable($locked);

            foreach ((array) ($options['impute_deposit_ids'] ?? []) as $depositId) {
                $deposit = EarnestAndSecurityDeposit::query()->where('tenant_id', $locked->tenant_id)->where('chain_id', $locked->id)->whereKey($depositId)->firstOrFail();
                $this->deposits->resolve($locked, $deposit, ['resolution' => DepositResolution::ImputeToPrice->value, 'date' => $options['date'] ?? null], $userId);
            }

            $locked->refresh();
            $this->assertNoOpenProformas($locked);
            $this->assertNoHeldEarnestMoney($locked);

            $supply = $this->supplyLines($locked);
            $deductions = $this->deductions($locked);
            $invoice = $this->invoicing->issueInvoice(array_filter([
                'tenant_id' => (string) $locked->tenant_id,
                'contact_id' => (string) $locked->customer_id,
                'sales_order_id' => $locked->sales_order_id,
                'quote_id' => $locked->source_type === 'quote' ? (string) $locked->source_id : null,
                'currency' => (string) $locked->currency,
                'jurisdiction' => (string) $locked->jurisdiction,
                'document_type' => $options['document_type'] ?? null,
                'date' => $options['date'] ?? null,
                'due_date' => $options['due_date'] ?? null,
                'payment_method' => $options['payment_method'] ?? null,
                'notes' => 'Final settlement of the down-payment chain '.$locked->chain_number,
                'created_by' => Str::isUuid($userId) ? $userId : null,
            ], static fn (mixed $value): bool => $value !== null), $this->itemsOf($supply, $deductions), $userId);

            $this->recordDeductions($locked, $invoice, $deductions);
            $this->recordFinalLink($locked, $invoice);
            $locked->forceFill(['final_invoice_id' => (string) $invoice->id])->save();
            $this->ledger->refresh($locked);
            FinalSettlementGenerated::dispatch((string) $locked->id, (string) $invoice->id, (string) $locked->tenant_id);

            return $invoice->refresh();
        });
    }

    /**
     * The deduction rows of the chain: per advance invoice and VAT rate, what is still open of it.
     *
     * @return list<DeductionRow>
     */
    public function deductions(DownPaymentChain $chain): array
    {
        $links = DownPaymentLink::query()
            ->with('vatLines')
            ->where('tenant_id', $chain->tenant_id)
            ->where('chain_id', $chain->id)
            ->where('link_type', LinkType::AdvanceInvoiceTd02->value)
            ->whereIn('status', [LinkStatus::Issued->value, LinkStatus::PartiallyCredited->value])
            ->orderBy('document_date')
            ->orderBy('document_number')
            ->get();
        $rows = [];

        foreach ($links as $link) {
            $itemsByRate = SalesInvoiceItem::withoutGlobalScope(TenantScope::class)->where('invoice_id', $link->document_id)->get()
                ->keyBy(static fn (SalesInvoiceItem $item): string => Decimal::round2(DocumentTotalsCalculator::decimal($item->tax_rate)));

            foreach ($link->vatLines as $line) {
                /** @var DownPaymentLinkVatLine $line */
                if (! Decimal::isPositive((string) $line->residual_net_amount)) {
                    continue;
                }

                $rate = Decimal::round2((string) $line->vat_percentage);
                $item = $itemsByRate->get($rate) ?? throw new DownPaymentException('advance_line_missing', 422, ['invoice' => (string) $link->document_number]);
                $rows[] = [
                    'link_id' => (string) $link->id,
                    'advance_item_id' => (string) $item->id,
                    'advance_invoice_number' => (string) $link->document_number,
                    'advance_invoice_date' => CarbonImmutable::parse($link->document_date)->toDateString(),
                    'vat_rate' => $rate,
                    'net' => (string) $line->residual_net_amount,
                    'vat' => (string) $line->residual_vat_amount,
                    'gross' => (string) $line->residual_gross_amount,
                ];
            }
        }

        return $rows;
    }

    /**
     * The supply the final invoice bills: the open lines of the order, the lines of the quote or the lines of the contract.
     *
     * @return list<array<string, mixed>>
     */
    public function supplyLines(DownPaymentChain $chain): array
    {
        if ($chain->sales_order_id !== null) {
            $order = SalesOrder::withoutGlobalScope(TenantScope::class)->with('items')->where('tenant_id', $chain->tenant_id)->whereKey($chain->sales_order_id)->firstOrFail();
            $lines = $this->billingLines->openOrderLines($order);
        } elseif ($chain->source_type === 'quote') {
            $quote = SalesQuote::withoutGlobalScope(TenantScope::class)->with('items')->where('tenant_id', $chain->tenant_id)->whereKey($chain->source_id)->firstOrFail();
            $lines = [];

            foreach ($quote->items as $item) {
                /** @var SalesQuoteItem $item */
                $lines[] = [
                    'description' => (string) $item->description,
                    'quantity' => DocumentTotalsCalculator::decimal($item->quantity),
                    'unit_price' => DocumentTotalsCalculator::decimal($item->unit_price),
                    'tax_rate' => DocumentTotalsCalculator::decimal($item->tax_rate ?? '0'),
                    'discount_percentage' => DocumentTotalsCalculator::decimal($item->discount_percentage ?? '0'),
                ];
            }
        } else {
            $lines = array_map(static fn (array $line): array => [
                'description' => $line['description'] !== '' ? $line['description'] : 'Supply',
                'quantity' => '1',
                'unit_price' => $line['net'],
                'tax_rate' => $line['vat_rate'],
                'discount_percentage' => '0',
            ], $chain->supply_lines ?? []);
        }

        if ($lines === []) {
            throw new DownPaymentException('nothing_left_to_settle');
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $supply
     * @param  list<DeductionRow>  $deductions
     * @return list<array<string, mixed>>
     */
    private function itemsOf(array $supply, array $deductions): array
    {
        $items = $supply;

        foreach ($deductions as $row) {
            $items[] = [
                'description' => (string) __('down_payments.deduction_line', ['number' => $row['advance_invoice_number'], 'date' => $row['advance_invoice_date'], 'rate' => rtrim(rtrim($row['vat_rate'], '0'), '.')]),
                'quantity' => '1',
                'unit_price' => Decimal::round2(Decimal::neg($row['net'])),
                'tax_rate' => $row['vat_rate'],
                'discount_percentage' => '0',
                'deducted_invoice_item_id' => $row['advance_item_id'],
            ];
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $supply
     * @param  list<DeductionRow>  $deductions
     * @param  array<string, mixed>  $totals
     * @return array<string, mixed>
     */
    private function describe(DownPaymentChain $chain, array $supply, array $deductions, array $totals): array
    {
        $supplyTotals = $this->totals->calculateDecimal($supply);
        $deductedNet = array_reduce($deductions, static fn (string $sum, array $row): string => Decimal::add($sum, $row['net']), '0');
        $deductedVat = array_reduce($deductions, static fn (string $sum, array $row): string => Decimal::add($sum, $row['vat']), '0');
        $deductedGross = array_reduce($deductions, static fn (string $sum, array $row): string => Decimal::add($sum, $row['gross']), '0');
        $supplyCount = count($supply);
        $linked = [];

        foreach ($deductions as $index => $row) {
            $linked[] = [
                'id_documento' => $row['advance_invoice_number'],
                'data' => $row['advance_invoice_date'],
                'riferimento_numero_linea' => $supplyCount + $index + 1,
                'num_item' => $this->advanceLinePosition($row),
            ];
        }

        $finalNet = Decimal::round2((string) $totals['total_net']);
        $finalVat = Decimal::round2((string) $totals['total_tax']);
        $finalGross = Decimal::round2((string) $totals['total_gross']);
        $supplyNet = Decimal::round2((string) $supplyTotals['total_net']);

        return [
            'chain_id' => (string) $chain->id,
            'currency' => (string) $chain->currency,
            'supply_lines' => array_map(static fn (array $line): array => [
                'description' => (string) $line['description'],
                'quantity' => (string) $line['quantity'],
                'unit_price' => (string) $line['unit_price'],
                'vat_rate' => Decimal::round2((string) $line['tax_rate']),
            ], $supply),
            'deduction_lines' => $deductions,
            'totals' => [
                'supply_net' => $supplyNet,
                'supply_vat' => Decimal::round2((string) $supplyTotals['total_tax']),
                'supply_gross' => Decimal::round2((string) $supplyTotals['total_gross']),
                'deducted_net' => Decimal::round2($deductedNet),
                'deducted_vat' => Decimal::round2($deductedVat),
                'deducted_gross' => Decimal::round2($deductedGross),
                'final_net' => $finalNet,
                'final_vat' => $finalVat,
                'final_gross' => $finalGross,
                'vat_rounding_difference' => Decimal::round2(Decimal::sub(Decimal::sub((string) $supplyTotals['total_tax'], $deductedVat), $finalVat)),
            ],
            'linked_documents' => $linked,
            'journal_preview' => [
                ['account' => 'receivables', 'debit' => $finalGross, 'credit' => '0.00'],
                ['account' => 'customer_advances', 'debit' => Decimal::round2($deductedNet), 'credit' => '0.00'],
                ['account' => 'sales_revenue', 'debit' => '0.00', 'credit' => $supplyNet],
                ['account' => 'output_vat', 'debit' => '0.00', 'credit' => $finalVat],
            ],
            'blockers' => $this->blockers($chain),
        ];
    }

    /**
     * What stops the settlement: open pro-forma requests and earnest money still held (it must be imputed, forfeited or refunded).
     *
     * @return list<array{code: string, id: string}>
     */
    private function blockers(DownPaymentChain $chain): array
    {
        $blockers = [];

        foreach (DownPaymentLink::query()->where('chain_id', $chain->id)->where('link_type', LinkType::ProformaAdvanceRequest->value)->where('status', LinkStatus::Open->value)->get() as $link) {
            $blockers[] = ['code' => 'open_proforma', 'id' => (string) $link->id];
        }

        foreach (EarnestAndSecurityDeposit::query()->where('chain_id', $chain->id)->whereIn('status', [DepositStatus::HeldActive->value, DepositStatus::IssuedUnpaid->value])->where('deposit_type', DepositType::EarnestMoney->value)->get() as $deposit) {
            $blockers[] = ['code' => 'held_earnest_money', 'id' => (string) $deposit->id];
        }

        return $blockers;
    }

    /**
     * @param  DeductionRow  $row
     */
    private function advanceLinePosition(array $row): int
    {
        $link = DownPaymentLink::query()->whereKey($row['link_id'])->first();
        $ids = SalesInvoiceItem::withoutGlobalScope(TenantScope::class)->where('invoice_id', $link?->document_id)->orderBy('id')->pluck('id')->map(static fn (mixed $id): string => (string) $id)->values()->all();
        $position = array_search($row['advance_item_id'], $ids, true);

        return $position === false ? 1 : $position + 1;
    }

    /**
     * @throws DownPaymentException
     */
    private function assertSettleable(DownPaymentChain $chain): void
    {
        if ($chain->final_invoice_id !== null) {
            throw new DownPaymentException('chain_already_settled');
        }

        if (in_array($chain->status, ['cancelled', 'forfeited'], true)) {
            throw new DownPaymentException('chain_closed');
        }

    }

    private function assertNoOpenProformas(DownPaymentChain $chain): void
    {
        if (DownPaymentLink::query()->where('chain_id', $chain->id)->where('link_type', LinkType::ProformaAdvanceRequest->value)->where('status', LinkStatus::Open->value)->exists()) {
            throw new DownPaymentException('open_proformas');
        }
    }

    private function assertNoHeldEarnestMoney(DownPaymentChain $chain): void
    {
        if (EarnestAndSecurityDeposit::query()->where('chain_id', $chain->id)->where('deposit_type', DepositType::EarnestMoney->value)->whereIn('status', [DepositStatus::HeldActive->value, DepositStatus::IssuedUnpaid->value])->exists()) {
            throw new DownPaymentException('held_earnest_money');
        }
    }

    /**
     * Stores the deduction lines of the issued invoice and takes what they deducted off the advance links, rate by rate.
     *
     * @param  list<DeductionRow>  $deductions
     */
    private function recordDeductions(DownPaymentChain $chain, SalesInvoice $invoice, array $deductions): void
    {
        $items = SalesInvoiceItem::withoutGlobalScope(TenantScope::class)->where('invoice_id', $invoice->id)->get()->keyBy(static fn (SalesInvoiceItem $item): string => (string) $item->deducted_invoice_item_id);

        foreach ($deductions as $row) {
            $link = DownPaymentLink::query()->with('vatLines')->whereKey($row['link_id'])->firstOrFail();
            $line = $link->vatLines->first(static fn (DownPaymentLinkVatLine $vatLine): bool => Decimal::compare(Decimal::round2((string) $vatLine->vat_percentage), $row['vat_rate']) === 0);

            FinalSettlementDeductionLine::query()->create([
                'tenant_id' => (string) $chain->tenant_id,
                'chain_id' => (string) $chain->id,
                'final_invoice_id' => (string) $invoice->id,
                'final_invoice_item_id' => $items->get($row['advance_item_id'])?->id,
                'referenced_link_id' => $row['link_id'],
                'referenced_invoice_number' => $row['advance_invoice_number'],
                'referenced_invoice_date' => $row['advance_invoice_date'],
                'vat_rate_id' => null,
                'vat_percentage' => $row['vat_rate'],
                'deducted_net' => $row['net'],
                'deducted_vat' => $row['vat'],
                'deducted_gross' => $row['gross'],
            ]);

            if ($line !== null) {
                $line->settled_net_amount = Decimal::round2(Decimal::add((string) $line->settled_net_amount, $row['net']));
                $line->settled_vat_amount = Decimal::round2(Decimal::add((string) $line->settled_vat_amount, $row['vat']));
                $line->settled_gross_amount = Decimal::round2(Decimal::add((string) $line->settled_gross_amount, $row['gross']));
                $line->refreshResidual();
                $line->save();
            }

            $link->settled_gross_amount = Decimal::round2(Decimal::add((string) $link->settled_gross_amount, $row['gross']));
            $open = (string) DownPaymentLinkVatLine::query()->where('down_payment_link_id', $link->id)->sum('residual_gross_amount');
            $link->status = Decimal::isPositive(Decimal::round2($open)) ? $link->status : LinkStatus::Settled->value;
            $link->save();
        }
    }

    private function recordFinalLink(DownPaymentChain $chain, SalesInvoice $invoice): void
    {
        DownPaymentLink::query()->create([
            'tenant_id' => (string) $chain->tenant_id,
            'chain_id' => (string) $chain->id,
            'source_type' => (string) $chain->source_type,
            'source_id' => (string) $chain->source_id,
            'link_type' => LinkType::FinalSettlementTd01->value,
            'document_id' => (string) $invoice->id,
            'document_number' => (string) $invoice->invoice_number,
            'document_date' => CarbonImmutable::parse($invoice->date)->toDateString(),
            'status' => LinkStatus::Issued->value,
            'net_amount' => Decimal::round2((string) $invoice->total_net),
            'vat_amount' => Decimal::round2((string) $invoice->total_tax),
            'gross_amount' => Decimal::round2((string) $invoice->total_gross),
        ]);
    }
}
