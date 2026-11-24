<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Models\WithholdingSettlement;
use Modules\GeneralLedger\Models\WithholdingSettlementLine;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\ItalianVat\Exceptions\ItalianVatException;
use Modules\ItalianVat\Models\CuCertification;
use Modules\ItalianVat\Models\F24Payment;

/**
 * Tracks the F24 payments of the withholdings (codice tributo 1040 for the self-employed, 1038 for commissions, the
 * contribution codes of the register) month by month, and reconciles them with the withholdings certified in the CU.
 */
final class F24PaymentService
{
    private const string TOLERANCE = '0.01';

    private const string DEFAULT_TAX_CODE = '1040';

    /**
     * Brings the F24 due of a year in line with the monthly withholding settlements. What was already recorded as paid by hand
     * is kept; a settlement marked as paid in the ledger counts as paid in full on its payment date.
     *
     * @return Collection<int, F24Payment>
     */
    public function sync(string $tenantId, int $year): Collection
    {
        $settlements = WithholdingSettlement::query()->where('tenant_id', $tenantId)->where('year', $year)->orderBy('month')->get();

        DB::transaction(function () use ($tenantId, $year, $settlements): void {
            foreach ($settlements as $settlement) {
                $lines = WithholdingSettlementLine::query()->where('withholding_settlement_id', $settlement->id)->get();
                $due = [];

                foreach ($lines as $line) {
                    $code = $line->f24_code !== null && $line->f24_code !== '' ? $line->f24_code : self::DEFAULT_TAX_CODE;
                    $due[$code] = Decimal::add($due[$code] ?? '0', (string) $line->withheld_amount);
                }

                foreach ($due as $code => $amount) {
                    $this->upsert($tenantId, $year, $settlement, (string) $code, Decimal::round2($amount));
                }
            }
        });

        return $this->list($tenantId, $year);
    }

    /**
     * @return Collection<int, F24Payment>
     */
    public function list(string $tenantId, int $year): Collection
    {
        return F24Payment::query()->where('tenant_id', $tenantId)->where('year', $year)->orderBy('month')->orderBy('tax_code')->get();
    }

    /**
     * Records a payment made with the F24. Amounts add up: a payment in two instalments leaves the row partial until it is
     * covered; more than due is flagged as overpaid, never silently absorbed.
     */
    public function recordPayment(string $tenantId, string $id, string $amount, string $paidAt, ?string $reference): F24Payment
    {
        $payment = F24Payment::query()->where('tenant_id', $tenantId)->whereKey($id)->first() ?? throw ItalianVatException::notFound('f24_not_found');

        if (! Decimal::isPositive($amount)) {
            throw ItalianVatException::invalid('f24_amount_must_be_positive');
        }

        $paid = Decimal::round2(Decimal::add((string) $payment->amount_paid, $amount));
        $payment->update([
            'amount_paid' => $paid,
            'paid_at' => CarbonImmutable::parse($paidAt)->toDateString(),
            'f24_reference' => $reference ?? $payment->f24_reference,
            'status' => $this->statusOf((string) $payment->amount_due, $paid),
        ]);

        return $payment;
    }

    /**
     * Withheld in the CU of the year, due and paid with the F24, per tax code, and the difference.
     *
     * @return array{year: int, certified_withheld: string, f24_due: string, f24_paid: string, due_difference: string, unpaid: string, status: string, by_tax_code: list<array<string, mixed>>}
     */
    public function reconcile(string $tenantId, int $year): array
    {
        $certified = '0';

        foreach (CuCertification::query()->where('tenant_id', $tenantId)->where('year', $year)->get(['withheld_amount', 'contributions_amount']) as $certification) {
            $certified = Decimal::add($certified, Decimal::add((string) $certification->withheld_amount, (string) $certification->contributions_amount));
        }

        $rows = [];
        $due = '0';
        $paid = '0';

        foreach ($this->list($tenantId, $year) as $payment) {
            $row = $rows[$payment->tax_code] ?? ['tax_code' => $payment->tax_code, 'due' => '0', 'paid' => '0'];
            $row['due'] = Decimal::add($row['due'], (string) $payment->amount_due);
            $row['paid'] = Decimal::add($row['paid'], (string) $payment->amount_paid);
            $rows[$payment->tax_code] = $row;
            $due = Decimal::add($due, (string) $payment->amount_due);
            $paid = Decimal::add($paid, (string) $payment->amount_paid);
        }

        $dueDifference = Decimal::round2(Decimal::sub($certified, $due));
        $unpaid = Decimal::round2(Decimal::sub($due, $paid));
        $balanced = Decimal::compare(Decimal::abs($dueDifference), self::TOLERANCE) < 0 && Decimal::compare(Decimal::abs($unpaid), self::TOLERANCE) < 0;

        return [
            'year' => $year,
            'certified_withheld' => Decimal::round2($certified),
            'f24_due' => Decimal::round2($due),
            'f24_paid' => Decimal::round2($paid),
            'due_difference' => $dueDifference,
            'unpaid' => $unpaid,
            'status' => $balanced ? 'balanced' : 'unbalanced',
            'by_tax_code' => array_values(array_map(static fn (array $row): array => [
                'tax_code' => $row['tax_code'],
                'due' => Decimal::round2($row['due']),
                'paid' => Decimal::round2($row['paid']),
                'difference' => Decimal::round2(Decimal::sub($row['due'], $row['paid'])),
            ], $rows)),
        ];
    }

    private function upsert(string $tenantId, int $year, WithholdingSettlement $settlement, string $code, string $due): void
    {
        $payment = F24Payment::query()->where('tenant_id', $tenantId)->where('year', $year)->where('month', $settlement->month)->where('tax_code', $code)->first();
        $settledInLedger = $settlement->isPaid();

        if ($payment === null) {
            $paid = $settledInLedger ? $due : '0.00';

            F24Payment::create([
                'tenant_id' => $tenantId,
                'year' => $year,
                'month' => $settlement->month,
                'tax_code' => $code,
                'amount_due' => $due,
                'amount_paid' => $paid,
                'due_date' => $settlement->due_date->toDateString(),
                'paid_at' => $settledInLedger ? $settlement->payment_date?->toDateString() : null,
                'withholding_settlement_id' => $settlement->id,
                'status' => $this->statusOf($due, $paid),
            ]);

            return;
        }

        $paid = $settledInLedger && ! Decimal::isPositive((string) $payment->amount_paid) ? $due : (string) $payment->amount_paid;

        $payment->update([
            'amount_due' => $due,
            'amount_paid' => $paid,
            'due_date' => $settlement->due_date->toDateString(),
            'paid_at' => $payment->paid_at?->toDateString() ?? ($settledInLedger ? $settlement->payment_date?->toDateString() : null),
            'withholding_settlement_id' => $settlement->id,
            'status' => $this->statusOf($due, $paid),
        ]);
    }

    private function statusOf(string $due, string $paid): string
    {
        $comparison = Decimal::compare(Decimal::round2($paid), Decimal::round2($due));

        return match (true) {
            ! Decimal::isPositive($paid) => 'due',
            $comparison < 0 => 'partial',
            $comparison > 0 => 'overpaid',
            default => 'paid',
        };
    }
}
