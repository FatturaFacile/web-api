<?php

declare(strict_types=1);

namespace Modules\DownPayments\Services;

use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\DownPayments\Domain\Enums\InstallmentStatus;
use Modules\DownPayments\Domain\Enums\LinkStatus;
use Modules\DownPayments\Domain\Enums\LinkType;
use Modules\DownPayments\Events\AdvanceCreditNoteIssued;
use Modules\DownPayments\Exceptions\DownPaymentException;
use Modules\DownPayments\Models\DownPaymentChain;
use Modules\DownPayments\Models\DownPaymentInstallment;
use Modules\DownPayments\Models\DownPaymentLink;
use Modules\DownPayments\Models\DownPaymentLinkVatLine;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * A credit note (TD04 in Italy, Gutschrift in Switzerland) against an advance invoice, partial or total: by amount, by VAT line or
 * for everything still open. The credit note references the advance (DatiFattureCollegate) and reverses its posting, the analytical
 * ledger of the chain takes the credited amounts back per VAT rate and the residual of the order opens again, so a replacement
 * advance or a larger final settlement can be issued.
 *
 * @phpstan-type Slice array{vat_rate: string, net: string, vat: string, gross: string}
 */
final class AdvanceCreditNoteService
{
    public const string MODE_TOTAL = 'total';

    public const string MODE_AMOUNT = 'amount';

    public const string MODE_LINES = 'lines';

    public function __construct(
        private readonly InvoicingServiceInterface $invoicing,
        private readonly MultiVatProportionalAllocator $allocator,
        private readonly ChainLedger $ledger,
    ) {}

    /**
     * What a credit note would take back and what the order would hold open afterwards.
     *
     * @param  array{mode: string, amount?: ?string, lines?: list<array{vat_rate: string, net: string}>}  $input
     * @return array{slices: list<Slice>, credited_net: string, credited_vat: string, credited_gross: string, residual_before_gross: string, residual_after_gross: string, advance_residual_after_gross: string}
     */
    public function preview(DownPaymentChain $chain, DownPaymentLink $link, array $input): array
    {
        $this->assertCreditable($link);
        $slices = $this->slices($link, $input);
        $net = $this->allocator->sum($slices, 'net');
        $vat = $this->allocator->sum($slices, 'vat');
        $gross = $this->allocator->sum($slices, 'gross');
        $before = (string) $chain->residual_order_gross;

        return [
            'slices' => $slices,
            'credited_net' => $net,
            'credited_vat' => $vat,
            'credited_gross' => $gross,
            'residual_before_gross' => $before,
            'residual_after_gross' => Decimal::round2(Decimal::add($before, $gross)),
            'advance_residual_after_gross' => Decimal::round2(Decimal::sub($link->residualGross(), $gross)),
        ];
    }

    /**
     * Issues the credit note and reopens the chain.
     *
     * @param  array{mode: string, amount?: ?string, lines?: list<array{vat_rate: string, net: string}>, date?: ?string, reason?: ?string}  $input
     *
     * @throws DownPaymentException when the advance cannot be credited or the amount exceeds what is open of it
     */
    public function issue(DownPaymentChain $chain, DownPaymentLink $link, array $input, string $userId): DownPaymentLink
    {
        return DB::transaction(function () use ($chain, $link, $input, $userId): DownPaymentLink {
            $locked = DownPaymentLink::query()->with('vatLines')->where('tenant_id', $chain->tenant_id)->where('chain_id', $chain->id)->whereKey($link->id)->lockForUpdate()->firstOrFail();
            $this->assertCreditable($locked);
            $slices = $this->slices($locked, $input);
            $advance = SalesInvoice::withoutGlobalScope(TenantScope::class)->where('tenant_id', $chain->tenant_id)->whereKey($locked->document_id)->firstOrFail();

            $creditNote = $this->invoicing->issueCreditNote($advance, array_filter([
                'date' => $input['date'] ?? null,
                'notes' => (string) __('down_payments.credit_note_notes', ['number' => (string) $locked->document_number, 'reason' => (string) ($input['reason'] ?? '')]),
                'created_by' => Str::isUuid($userId) ? $userId : null,
            ], static fn (mixed $value): bool => $value !== null), array_map(static fn (array $slice): array => [
                'description' => (string) __('down_payments.credit_line', ['number' => (string) $locked->document_number]),
                'quantity' => '1',
                'unit_price' => $slice['net'],
                'tax_rate' => $slice['vat_rate'],
                'discount_percentage' => '0',
            ], $slices));

            $credit = $this->recordCredit($chain, $locked, $creditNote, $slices);
            $this->reopenInstallment($locked);
            $this->ledger->refresh($chain);
            AdvanceCreditNoteIssued::dispatch((string) $chain->id, (string) $locked->id, (string) $creditNote->id, (string) $chain->tenant_id);

            return $credit;
        });
    }

    /**
     * @param  array{mode: string, amount?: ?string, lines?: list<array{vat_rate: string, net: string}>}  $input
     * @return list<Slice>
     */
    private function slices(DownPaymentLink $link, array $input): array
    {
        $link->loadMissing('vatLines');
        $open = [];

        foreach ($link->vatLines as $line) {
            $open[Decimal::round2((string) $line->vat_percentage)] = (string) $line->residual_net_amount;
        }

        $slices = match ($input['mode']) {
            self::MODE_TOTAL => array_values(array_filter(array_map(fn (string $rate): array => $this->allocator->slice($rate, $open[$rate]), array_keys($open)), static fn (array $slice): bool => Decimal::isPositive($slice['net']))),
            self::MODE_AMOUNT => $this->byAmount($input, $open),
            self::MODE_LINES => $this->byLines($input, $open),
            default => throw new DownPaymentException('credit_mode_invalid'),
        };

        if ($slices === []) {
            throw new DownPaymentException('credit_nothing_open');
        }

        foreach ($slices as $slice) {
            if (Decimal::compare($slice['net'], $open[$slice['vat_rate']] ?? '0') > 0) {
                throw new DownPaymentException('credit_exceeds_advance', 422, ['residual' => $link->residualGross()]);
            }
        }

        return $slices;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $open
     * @return list<Slice>
     */
    private function byAmount(array $input, array $open): array
    {
        $amount = Decimal::round2((string) ($input['amount'] ?? '0'));

        if (! Decimal::isPositive($amount)) {
            throw new DownPaymentException('credit_amount_invalid');
        }

        $openPositive = array_filter($open, static fn (string $net): bool => Decimal::isPositive($net));

        if ($openPositive === []) {
            throw new DownPaymentException('credit_nothing_open');
        }

        return $this->allocator->allocateGross($amount, $openPositive);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $open
     * @return list<Slice>
     */
    private function byLines(array $input, array $open): array
    {
        $slices = [];

        foreach ((array) ($input['lines'] ?? []) as $line) {
            $rate = Decimal::round2((string) ($line['vat_rate'] ?? '0'));
            $net = Decimal::round2((string) ($line['net'] ?? '0'));

            if (! array_key_exists($rate, $open) || ! Decimal::isPositive($net)) {
                throw new DownPaymentException('credit_line_invalid', 422, ['vat_rate' => $rate]);
            }

            $slices[$rate] = $this->allocator->slice($rate, Decimal::add($slices[$rate]['net'] ?? '0', $net));
        }

        return array_values($slices);
    }

    /**
     * @param  list<Slice>  $slices
     */
    private function recordCredit(DownPaymentChain $chain, DownPaymentLink $advance, SalesInvoice $creditNote, array $slices): DownPaymentLink
    {
        $net = $this->allocator->sum($slices, 'net');
        $vat = $this->allocator->sum($slices, 'vat');
        $gross = $this->allocator->sum($slices, 'gross');
        $credit = DownPaymentLink::query()->create([
            'tenant_id' => (string) $chain->tenant_id,
            'chain_id' => (string) $chain->id,
            'source_type' => (string) $chain->source_type,
            'source_id' => (string) $chain->source_id,
            'installment_id' => $advance->installment_id,
            'link_type' => LinkType::CreditNoteTd04->value,
            'document_id' => (string) $creditNote->id,
            'document_number' => (string) $creditNote->invoice_number,
            'document_date' => CarbonImmutable::parse($creditNote->date)->toDateString(),
            'parent_link_id' => (string) $advance->id,
            'status' => LinkStatus::Issued->value,
            'net_amount' => $net,
            'vat_amount' => $vat,
            'gross_amount' => $gross,
        ]);

        foreach ($slices as $slice) {
            $creditLine = new DownPaymentLinkVatLine([
                'tenant_id' => (string) $chain->tenant_id,
                'down_payment_link_id' => (string) $credit->id,
                'vat_code' => ($chain->jurisdiction === 'CH' ? 'MWST ' : 'IVA ').rtrim(rtrim($slice['vat_rate'], '0'), '.').'%',
                'vat_percentage' => $slice['vat_rate'],
                'net_amount' => $slice['net'],
                'vat_amount' => $slice['vat'],
                'gross_amount' => $slice['gross'],
                'credited_net_amount' => '0.00', 'credited_vat_amount' => '0.00', 'credited_gross_amount' => '0.00',
                'settled_net_amount' => '0.00', 'settled_vat_amount' => '0.00', 'settled_gross_amount' => '0.00',
            ]);
            $creditLine->refreshResidual();
            $creditLine->save();

            $line = $advance->vatLines->first(static fn (DownPaymentLinkVatLine $vatLine): bool => Decimal::compare(Decimal::round2((string) $vatLine->vat_percentage), $slice['vat_rate']) === 0);

            if ($line !== null) {
                $line->credited_net_amount = Decimal::round2(Decimal::add((string) $line->credited_net_amount, $slice['net']));
                $line->credited_vat_amount = Decimal::round2(Decimal::add((string) $line->credited_vat_amount, $slice['vat']));
                $line->credited_gross_amount = Decimal::round2(Decimal::add((string) $line->credited_gross_amount, $slice['gross']));
                $line->refreshResidual();
                $line->save();
            }
        }

        $advance->credited_gross_amount = Decimal::round2(Decimal::add((string) $advance->credited_gross_amount, $gross));
        $open = Decimal::round2((string) DownPaymentLinkVatLine::query()->where('down_payment_link_id', $advance->id)->sum('residual_gross_amount'));
        $advance->status = Decimal::isPositive($open) ? LinkStatus::PartiallyCredited->value : (Decimal::isPositive((string) $advance->settled_gross_amount) ? LinkStatus::Settled->value : LinkStatus::Credited->value);
        $advance->save();

        return $credit->load('vatLines');
    }

    /**
     * A fully credited advance frees its installment, which can be emitted again.
     */
    private function reopenInstallment(DownPaymentLink $advance): void
    {
        if ($advance->installment_id === null || $advance->status !== LinkStatus::Credited->value) {
            return;
        }

        DownPaymentInstallment::query()->whereKey($advance->installment_id)->update(['status' => InstallmentStatus::Planned->value, 'linked_invoice_id' => null, 'linked_document_id' => null]);
    }

    /**
     * @throws DownPaymentException
     */
    private function assertCreditable(DownPaymentLink $link): void
    {
        if ($link->link_type !== LinkType::AdvanceInvoiceTd02->value) {
            throw new DownPaymentException('link_not_an_advance');
        }

        if (! in_array($link->status, [LinkStatus::Issued->value, LinkStatus::PartiallyCredited->value], true)) {
            throw new DownPaymentException('advance_not_creditable', 422, ['status' => (string) $link->status]);
        }
    }
}
