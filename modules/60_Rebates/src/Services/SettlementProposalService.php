<?php

declare(strict_types=1);

namespace Modules\Rebates\Services;

use App\Models\PassiveInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Rebates\Contracts\RebateLedgerPosterInterface;
use Modules\Rebates\Models\RebateAccrual;
use Modules\Rebates\Models\RebateAccrualRun;
use Modules\Rebates\Models\RebateAgreement;
use Modules\Rebates\Models\RebateContribution;
use Modules\Rebates\Models\RebateSettlement;
use Modules\Rebates\Models\RebateSettlementLine;

/**
 * Settlement proposals of a supplier rebate (proposte di liquidazione). A proposal gathers the open accruals of the
 * agreement with the analytical breakdown (invoices and credit notes with their share of the rebate, contributions,
 * fixed bonus), the gross, net and VAT amounts and the way it will be executed. It follows the approval workflow
 * draft, pending approval, approved, settled (or cancelled). Executing it requests a credit note from the supplier,
 * issues an active charge invoice, or sets the amount off against an open payable of the supplier.
 */
final readonly class SettlementProposalService
{
    public function __construct(private InvoicingServiceInterface $invoicing, private RebateLedgerPosterInterface $ledger) {}

    /**
     * @param  array{execution_mode: string, tax_rate?: string|float|int|null, passive_invoice_id?: string|null, notes?: string|null}  $data
     */
    public function propose(RebateAgreement $agreement, array $data, ?string $userId): RebateSettlement
    {
        return DB::transaction(function () use ($agreement, $data, $userId): RebateSettlement {
            $locked = RebateAgreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();

            if ($locked->direction !== RebateAgreement::Purchase) {
                throw ValidationException::withMessages(['agreement' => [__('rebate_errors.settlement.purchase_only')]]);
            }

            $mode = $data['execution_mode'];
            $rate = isset($data['tax_rate']) ? Decimal::round2((string) $data['tax_rate']) : null;

            if ($rate === null && $mode !== RebateSettlement::ApSetOff) {
                throw ValidationException::withMessages(['tax_rate' => [__('rebate_errors.settlement.tax_rate_required')]]);
            }

            $open = RebateAccrual::query()->where('agreement_id', $locked->id)->where('status', RebateAccrual::Accrued)->lockForUpdate()->get();
            $amount = Decimal::round2($open->reduce(static fn (string $sum, RebateAccrual $accrual): string => Decimal::add($sum, (string) $accrual->rebate_amount), '0'));

            if (! Decimal::isPositive($amount)) {
                throw ValidationException::withMessages(['agreement' => [__('rebate_errors.settlement.nothing_to_settle')]]);
            }

            $tax = Decimal::round2(Decimal::div(Decimal::mul($amount, $rate ?? '0'), '100'));
            $gross = Decimal::round2(Decimal::add($amount, $tax));
            $invoiceId = $data['passive_invoice_id'] ?? null;

            if ($mode === RebateSettlement::ApSetOff) {
                $this->assertSetOffTarget($locked, $invoiceId, $gross, false);
            }

            $settlement = RebateSettlement::create([
                'tenant_id' => $locked->tenant_id,
                'agreement_id' => $locked->id,
                'amount' => $amount,
                'gross_amount' => $gross,
                'tax_rate' => $rate,
                'tax_amount' => $tax,
                'currency' => $locked->currency,
                'settlement_type' => RebateSettlement::SupplierClaim,
                'execution_mode' => $mode,
                'passive_invoice_id' => $mode === RebateSettlement::ApSetOff ? $invoiceId : null,
                'status' => RebateSettlement::Draft,
                'version' => 1,
                'settled_on' => now()->toDateString(),
                'notes' => $data['notes'] ?? null,
            ]);

            RebateAccrual::query()->whereIn('id', $open->pluck('id'))->update(['status' => RebateAccrual::Proposed, 'settlement_id' => $settlement->id]);
            $this->buildLines($settlement, $locked, $open->pluck('id')->all(), $amount);

            return $settlement->load(['lines', 'accruals', 'agreement']);
        });
    }

    public function submit(RebateSettlement $settlement, ?int $version): RebateSettlement
    {
        return $this->transition($settlement, [RebateSettlement::Draft], RebateSettlement::PendingApproval, $version, ['submitted_at' => now()]);
    }

    public function approve(RebateSettlement $settlement, ?int $version, ?string $userId): RebateSettlement
    {
        return $this->transition($settlement, [RebateSettlement::PendingApproval], RebateSettlement::Approved, $version, ['approved_by' => $userId, 'approved_at' => now()]);
    }

    public function cancel(RebateSettlement $settlement, ?int $version): RebateSettlement
    {
        return DB::transaction(function () use ($settlement, $version): RebateSettlement {
            $result = $this->transition($settlement, [RebateSettlement::Draft, RebateSettlement::PendingApproval, RebateSettlement::Approved], RebateSettlement::Cancelled, $version, ['cancelled_at' => now()]);

            RebateAccrual::query()->where('settlement_id', $result->id)->update(['status' => RebateAccrual::Accrued, 'settlement_id' => null]);

            return $result->load(['lines', 'accruals', 'agreement']);
        });
    }

    /**
     * @param  array{executed_on?: string|null, payables_account_id?: string|null, vat_account_id?: string|null}  $data
     */
    public function execute(RebateSettlement $settlement, array $data, ?int $version, ?string $userId): RebateSettlement
    {
        return DB::transaction(function () use ($settlement, $data, $version, $userId): RebateSettlement {
            $locked = RebateSettlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            $this->assertTransition($locked, [RebateSettlement::Approved], $version);
            $agreement = RebateAgreement::query()->findOrFail($locked->agreement_id);
            $on = (string) ($data['executed_on'] ?? now()->toDateString());
            $updates = ['settled_on' => $on];

            if ($locked->execution_mode === RebateSettlement::ChargeInvoice) {
                $updates['credit_note_id'] = (string) $this->invoicing->createInvoice([
                    'tenant_id' => (string) $locked->tenant_id,
                    'contact_id' => (string) $agreement->contact_id,
                    'invoice_type' => 'standard',
                    'date' => $on,
                    'currency' => $locked->currency,
                    'notes' => $locked->notes ?? __('rebate_errors.settlement.charge_note', ['name' => $agreement->name, 'code' => $agreement->code]),
                    'skip_charge_lines' => true,
                    'created_by' => $userId,
                ], [[
                    'description' => __('rebate_errors.settlement.charge_line', ['name' => $agreement->name, 'from' => $agreement->starts_on->toDateString(), 'to' => $agreement->ends_on->toDateString()]),
                    'quantity' => '1',
                    'unit_price' => (string) $locked->amount,
                    'tax_rate' => (string) ($locked->tax_rate ?? '0'),
                ]])->id;
            }

            if ($locked->execution_mode === RebateSettlement::ApSetOff) {
                $updates = [...$updates, ...$this->setOff($locked, $agreement, $data, $on, $userId)];
            }

            $locked->forceFill([...$updates, 'status' => RebateSettlement::Settled, 'version' => $locked->version + 1])->save();

            RebateAccrual::query()->where('settlement_id', $locked->id)->update(['status' => RebateAccrual::Settled]);
            RebateContribution::query()->whereIn('accrual_id', RebateAccrual::query()->where('settlement_id', $locked->id)->select('id'))->update(['status' => RebateContribution::Settled]);

            return $locked->refresh()->load(['lines', 'accruals', 'agreement']);
        });
    }

    /**
     * @param  array{executed_on?: string|null, payables_account_id?: string|null, vat_account_id?: string|null}  $data
     * @return array<string, mixed>
     */
    private function setOff(RebateSettlement $settlement, RebateAgreement $agreement, array $data, string $on, ?string $userId): array
    {
        $gross = (string) ($settlement->gross_amount ?? $settlement->amount);
        $invoice = $this->assertSetOffTarget($agreement, $settlement->passive_invoice_id, $gross, true);
        $paid = Decimal::round2(Decimal::add((string) $invoice->total_paid, $gross));

        $invoice->forceFill([
            'total_paid' => $paid,
            'payment_status' => Decimal::compare($paid, (string) $invoice->total_amount) >= 0 ? 'paid' : 'partially_paid',
        ])->save();

        $result = ['set_off_amount' => $gross];
        $payables = $data['payables_account_id'] ?? null;

        if ($payables !== null && $agreement->accrual_account_id !== null) {
            $tax = (string) ($settlement->tax_amount ?? '0');
            $vat = $data['vat_account_id'] ?? null;

            if (Decimal::isPositive($tax) && $vat === null) {
                throw ValidationException::withMessages(['vat_account_id' => [__('rebate_errors.settlement.vat_account_required')]]);
            }

            $result['journal_entry_id'] = $this->ledger->postSetOff(
                (string) $settlement->tenant_id,
                $on,
                (string) $settlement->amount,
                $tax,
                $payables,
                (string) $agreement->accrual_account_id,
                $vat,
                (string) $settlement->id,
                __('rebate_errors.settlement.set_off_description', ['code' => $agreement->code, 'invoice' => (string) $invoice->invoice_number]),
                $userId,
            );
        }

        return $result;
    }

    /**
     * The payable the rebate is set off against: an invoice (not a credit note) of the supplier of the agreement with an
     * open balance that covers the amount.
     */
    private function assertSetOffTarget(RebateAgreement $agreement, ?string $invoiceId, string $gross, bool $lock): PassiveInvoice
    {
        if ($invoiceId === null) {
            throw ValidationException::withMessages(['passive_invoice_id' => [__('rebate_errors.settlement.invoice_required')]]);
        }

        $query = PassiveInvoice::query()->where('tenant_id', $agreement->tenant_id)->where('supplier_id', $agreement->contact_id)->where('document_type', '!=', 'TD04');
        $invoice = ($lock ? $query->lockForUpdate() : $query)->find($invoiceId);

        if ($invoice === null) {
            throw ValidationException::withMessages(['passive_invoice_id' => [__('rebate_errors.settlement.invoice_not_found')]]);
        }

        $open = Decimal::sub((string) $invoice->total_amount, (string) $invoice->total_paid);

        if (Decimal::compare($open, $gross) < 0) {
            throw ValidationException::withMessages(['passive_invoice_id' => [__('rebate_errors.settlement.insufficient_open_balance', ['open' => Decimal::round2($open)])]]);
        }

        return $invoice;
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $extra
     */
    private function transition(RebateSettlement $settlement, array $from, string $to, ?int $version, array $extra): RebateSettlement
    {
        return DB::transaction(function () use ($settlement, $from, $to, $version, $extra): RebateSettlement {
            $locked = RebateSettlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            $this->assertTransition($locked, $from, $version);
            $locked->forceFill([...$extra, 'status' => $to, 'version' => $locked->version + 1])->save();

            return $locked->refresh()->load(['lines', 'accruals', 'agreement']);
        });
    }

    /**
     * @param  list<string>  $from
     */
    private function assertTransition(RebateSettlement $settlement, array $from, ?int $version): void
    {
        if (! in_array($settlement->status, $from, true)) {
            throw ValidationException::withMessages(['status' => [__('rebate_errors.settlement.invalid_transition', ['status' => $settlement->status])]]);
        }

        if ($version !== null && $version !== $settlement->version) {
            throw ValidationException::withMessages(['version' => [__('rebate_errors.settlement.version_conflict')]]);
        }
    }

    /**
     * Analytical breakdown: the contributions, the invoices and credit notes of the latest accrual run with their share
     * of the volume rebate (the last row takes the rounding remainder), and the fixed bonus.
     *
     * @param  list<string>  $accrualIds
     */
    private function buildLines(RebateSettlement $settlement, RebateAgreement $agreement, array $accrualIds, string $amount): void
    {
        $contributions = RebateContribution::query()->whereIn('accrual_id', $accrualIds)->orderBy('due_on')->get();
        $contributionTotal = $contributions->reduce(static fn (string $sum, RebateContribution $item): string => Decimal::add($sum, (string) $item->amount), '0');
        $volumeRebate = Decimal::round2(Decimal::sub($amount, $contributionTotal));

        foreach ($contributions as $item) {
            RebateSettlementLine::create([
                'tenant_id' => $settlement->tenant_id,
                'settlement_id' => $settlement->id,
                'line_type' => RebateSettlementLine::Contribution,
                'reference' => $item->description,
                'reference_date' => $item->due_on->toDateString(),
                'net_amount' => (string) $item->amount,
                'rebate_amount' => (string) $item->amount,
            ]);
        }

        $run = RebateAccrualRun::query()
            ->whereIn('id', RebateAccrual::query()->whereIn('id', $accrualIds)->whereNotNull('accrual_run_id')->select('accrual_run_id'))
            ->where('status', RebateAccrualRun::Posted)
            ->orderByDesc('period_end')
            ->first();

        if ($run === null) {
            return;
        }

        $lines = $run->lines()->get();
        $base = (string) $run->base_amount;
        $fixed = $agreement->rebate_type === RebateAgreement::FixedBonus;
        $distributed = '0';

        foreach ($lines as $index => $line) {
            $share = '0.00';

            if (! $fixed && ! Decimal::isZero($base)) {
                $share = $index === $lines->count() - 1
                    ? Decimal::sub($volumeRebate, $distributed)
                    : Decimal::round2(Decimal::div(Decimal::mul((string) $line->net_amount, $volumeRebate), $base));
                $distributed = Decimal::add($distributed, $share);
            }

            RebateSettlementLine::create([
                'tenant_id' => $settlement->tenant_id,
                'settlement_id' => $settlement->id,
                'line_type' => $line->document_type === 'TD04' ? RebateSettlementLine::CreditNote : RebateSettlementLine::Invoice,
                'passive_invoice_id' => $line->passive_invoice_id,
                'reference' => $line->invoice_number,
                'reference_date' => $line->invoice_date?->toDateString(),
                'net_amount' => (string) $line->net_amount,
                'rebate_amount' => Decimal::round2($share),
            ]);
        }

        if ($fixed && Decimal::isPositive($volumeRebate)) {
            RebateSettlementLine::create([
                'tenant_id' => $settlement->tenant_id,
                'settlement_id' => $settlement->id,
                'line_type' => RebateSettlementLine::Bonus,
                'reference' => $agreement->code,
                'reference_date' => $run->period_end->toDateString(),
                'net_amount' => $base,
                'rebate_amount' => $volumeRebate,
            ]);
        }
    }
}
