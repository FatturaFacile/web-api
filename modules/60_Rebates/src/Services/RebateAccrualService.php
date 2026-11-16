<?php

declare(strict_types=1);

namespace Modules\Rebates\Services;

use App\Models\Invoice;
use App\Models\PassiveInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Rebates\Models\RebateAccrual;
use Modules\Rebates\Models\RebateAgreement;
use Modules\Rebates\Models\RebateSettlement;

/**
 * Accrual and settlement of the rebate of an agreement. The volume is the net value invoiced to the customer (issued
 * invoices minus credit notes) or invoiced by the supplier, from the start of the agreement up to the calculation date;
 * every run accrues only what the cumulative rebate has gained since the previous runs, so repeating a run is harmless.
 * The settlement turns the open accruals into a credit note draft (customer) or a claim (supplier).
 */
final readonly class RebateAccrualService
{
    private const string IssuedStatus = 'posted';

    public function __construct(private RebateTierCalculator $calculator, private InvoicingServiceInterface $invoicing) {}

    /**
     * @return array{accrual: RebateAccrual|null, base_amount: string, cumulative_rebate: string, previously_accrued: string, delta: string}
     */
    public function calculate(RebateAgreement $agreement, Carbon $asOf): array
    {
        return DB::transaction(function () use ($agreement, $asOf): array {
            $locked = RebateAgreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RebateAgreement::Active) {
                throw ValidationException::withMessages(['agreement' => [__('rebate_errors.agreement.not_active')]]);
            }

            if ($asOf->lt($locked->starts_on) || $asOf->gt($locked->ends_on)) {
                throw ValidationException::withMessages(['as_of' => [__('rebate_errors.accrual.date_outside', ['from' => $locked->starts_on->toDateString(), 'to' => $locked->ends_on->toDateString()])]]);
            }

            $volume = $this->volume($locked, $asOf);
            $tiers = $locked->tiers->map(static fn ($tier): array => ['threshold_amount' => (string) $tier->threshold_amount, 'rate_percent' => (string) $tier->rate_percent])->all();
            $earned = $this->calculator->rebateOn($tiers, $volume, $locked->tier_mode);
            $previous = $this->accruedTotal($locked);
            $delta = Decimal::round2(Decimal::sub($earned['rebate'], $previous));
            $accrual = null;

            if (! Decimal::isZero($delta)) {
                $accrual = RebateAccrual::create([
                    'tenant_id' => $locked->tenant_id,
                    'agreement_id' => $locked->id,
                    'as_of' => $asOf->toDateString(),
                    'base_amount' => $volume,
                    'cumulative_rebate' => $earned['rebate'],
                    'rebate_amount' => $delta,
                    'applied_rate_percent' => $earned['rate_percent'],
                    'status' => RebateAccrual::Accrued,
                ]);
            }

            return ['accrual' => $accrual, 'base_amount' => $volume, 'cumulative_rebate' => $earned['rebate'], 'previously_accrued' => $previous, 'delta' => $delta];
        });
    }

    /**
     * @param  array{tax_rate?: string|float|int|null, settled_on?: string|null, notes?: string|null}  $data
     */
    public function settle(RebateAgreement $agreement, array $data, ?string $userId): RebateSettlement
    {
        return DB::transaction(function () use ($agreement, $data, $userId): RebateSettlement {
            $locked = RebateAgreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();
            $open = RebateAccrual::query()->where('agreement_id', $locked->id)->where('status', RebateAccrual::Accrued)->lockForUpdate()->get();
            $amount = Decimal::round2($open->reduce(static fn (string $sum, RebateAccrual $accrual): string => Decimal::add($sum, (string) $accrual->rebate_amount), '0'));

            if (! Decimal::isPositive($amount)) {
                throw ValidationException::withMessages(['agreement' => [__('rebate_errors.settlement.nothing_to_settle')]]);
            }

            $settledOn = (string) ($data['settled_on'] ?? now()->toDateString());
            $creditNoteId = null;

            if ($locked->isSales()) {
                if (! isset($data['tax_rate'])) {
                    throw ValidationException::withMessages(['tax_rate' => [__('rebate_errors.settlement.tax_rate_required')]]);
                }

                $creditNoteId = (string) $this->invoicing->createInvoice([
                    'tenant_id' => (string) $locked->tenant_id,
                    'contact_id' => (string) $locked->contact_id,
                    'invoice_type' => 'credit_note',
                    'date' => $settledOn,
                    'currency' => $locked->currency,
                    'notes' => $data['notes'] ?? __('rebate_errors.settlement.credit_note_note', ['name' => $locked->name, 'code' => $locked->code]),
                    'skip_charge_lines' => true,
                    'created_by' => $userId,
                ], [[
                    'description' => __('rebate_errors.settlement.credit_note_line', ['name' => $locked->name, 'from' => $locked->starts_on->toDateString(), 'to' => $locked->ends_on->toDateString()]),
                    'quantity' => '1',
                    'unit_price' => $amount,
                    'tax_rate' => (string) $data['tax_rate'],
                ]])->id;
            }

            $settlement = RebateSettlement::create([
                'tenant_id' => $locked->tenant_id,
                'agreement_id' => $locked->id,
                'amount' => $amount,
                'currency' => $locked->currency,
                'settlement_type' => $locked->isSales() ? RebateSettlement::CreditNoteDraft : RebateSettlement::SupplierClaim,
                'credit_note_id' => $creditNoteId,
                'settled_on' => $settledOn,
                'notes' => $data['notes'] ?? null,
            ]);

            RebateAccrual::query()->whereIn('id', $open->pluck('id'))->update(['status' => RebateAccrual::Settled, 'settlement_id' => $settlement->id]);

            return $settlement->load('accruals');
        });
    }

    /**
     * Net value invoiced in the agreement period up to the date: invoices minus credit notes, excluding proformas and the
     * credit notes this module issued for its own settlements.
     */
    public function volume(RebateAgreement $agreement, Carbon $asOf): string
    {
        return $agreement->isSales() ? $this->salesVolume($agreement, $asOf) : $this->purchaseVolume($agreement, $asOf);
    }

    private function salesVolume(RebateAgreement $agreement, Carbon $asOf): string
    {
        $query = static fn () => Invoice::query()
            ->where('tenant_id', $agreement->tenant_id)
            ->where('contact_id', $agreement->contact_id)
            ->where('status', self::IssuedStatus)
            ->whereDate('date', '>=', $agreement->starts_on->toDateString())
            ->whereDate('date', '<=', $asOf->toDateString());

        $invoiced = (string) ($query()->whereNotIn('invoice_type', ['credit_note', 'proforma'])->sum('total_net') ?? 0);
        $credited = (string) ($query()
            ->where('invoice_type', 'credit_note')
            ->whereNotIn('id', RebateSettlement::query()->whereNotNull('credit_note_id')->select('credit_note_id'))
            ->sum('total_net') ?? 0);

        return Decimal::round2(Decimal::sub($invoiced, $credited));
    }

    private function purchaseVolume(RebateAgreement $agreement, Carbon $asOf): string
    {
        $query = static fn () => PassiveInvoice::query()
            ->where('tenant_id', $agreement->tenant_id)
            ->where('supplier_id', $agreement->contact_id)
            ->whereDate('date', '>=', $agreement->starts_on->toDateString())
            ->whereDate('date', '<=', $asOf->toDateString());

        $invoiced = (string) ($query()->where('document_type', '!=', 'TD04')->sum('total_net') ?? 0);
        $credited = (string) ($query()->where('document_type', 'TD04')->sum('total_net') ?? 0);

        return Decimal::round2(Decimal::sub($invoiced, $credited));
    }

    private function accruedTotal(RebateAgreement $agreement): string
    {
        return Decimal::round2((string) (RebateAccrual::query()->where('agreement_id', $agreement->id)->sum('rebate_amount') ?? 0));
    }
}
