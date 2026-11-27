<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\ForeignVatRefund\Domain\Enums\ClaimStatus;
use Modules\ForeignVatRefund\Domain\Enums\LedgerStatus;
use Modules\ForeignVatRefund\Models\CountryVatRefundRule;
use Modules\ForeignVatRefund\Models\FiscalRepresentativeMandate;
use Modules\ForeignVatRefund\Models\ForeignVatLedgerEntry;
use Modules\ForeignVatRefund\Models\VatRefundClaim;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The threshold and deadline monitor: per refund country and fiscal year, the recoverable VAT accumulated and not yet claimed against
 * the minimum amounts of the procedure, the days left to its peremptory deadline (30 June ESTV, 30 September EU / Italy) and the state
 * of the fiscal representative mandate and certificate of taxable status the procedure requires.
 */
final class ThresholdMonitor
{
    public const string MET = 'THRESHOLD_MET';

    public const string BELOW = 'BELOW_THRESHOLD';

    public const string EXPIRED = 'EXPIRED';

    public function __construct(private readonly RefundRuleResolver $rules) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function overview(string $tenantId, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $home = $this->rules->homeCountry($tenantId);
        $entries = ForeignVatLedgerEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', LedgerStatus::EligibleAccumulating->value)
            ->whereNull('vat_refund_claim_id')
            ->whereNull('deleted_at')
            ->get();
        $rows = [];

        /** @var Collection<string, Collection<int, ForeignVatLedgerEntry>> $groups */
        $groups = $entries->groupBy(fn (ForeignVatLedgerEntry $entry): string => $entry->supplier_country_code.'|'.$entry->fiscal_year);

        foreach ($groups as $key => $items) {
            [$country, $year] = explode('|', $key);
            $procedure = $this->rules->procedure($tenantId, $home, $country);

            if ($procedure === null) {
                continue;
            }

            $rows[] = $this->row($tenantId, $procedure, $country, (int) $year, $items, $today);
        }

        usort($rows, static fn (array $a, array $b): int => [$a['deadline_date'], $a['country_code']] <=> [$b['deadline_date'], $b['country_code']]);

        return $rows;
    }

    /**
     * The days to the peremptory deadline of the claims of a fiscal year: the deadline is on the day of the procedure in the following year.
     */
    public function deadlineOf(CountryVatRefundRule $procedure, int $fiscalYear): CarbonImmutable
    {
        $month = max(1, min(12, $procedure->filing_deadline_month));
        $first = CarbonImmutable::create($fiscalYear + 1, $month, 1)->startOfDay();

        return $first->setDay(min($procedure->filing_deadline_day, $first->daysInMonth));
    }

    /**
     * 60, 30 or 15 when that many days or fewer are left, EXPIRED after the deadline, null earlier.
     */
    public function alertLevel(int $daysRemaining): int|string|null
    {
        if ($daysRemaining < 0) {
            return self::EXPIRED;
        }

        $levels = (array) config('foreign_vat_refund.alert_levels', [60, 30, 15]);
        sort($levels);

        foreach ($levels as $level) {
            if ($daysRemaining <= $level) {
                return (int) $level;
            }
        }

        return null;
    }

    /**
     * State of the mandate and certificate the procedure requires, on a given day.
     *
     * @return array{status: string, mandate_id: string|null, certificate_expiry_date: string|null}
     */
    public function mandateStatus(string $tenantId, string $country, CarbonImmutable $on, bool $requiresRepresentative, bool $requiresCertificate): array
    {
        if (! $requiresRepresentative && ! $requiresCertificate) {
            return ['status' => 'NOT_REQUIRED', 'mandate_id' => null, 'certificate_expiry_date' => null];
        }

        $moment = Carbon::instance($on);
        $mandate = FiscalRepresentativeMandate::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('country_code', strtoupper($country))
            ->whereNull('deleted_at')
            ->get()
            ->filter(fn (FiscalRepresentativeMandate $candidate): bool => $candidate->coversDate($moment))
            ->sortByDesc('mandate_start_date')
            ->first();

        if ($mandate === null) {
            return ['status' => 'MISSING', 'mandate_id' => null, 'certificate_expiry_date' => null];
        }

        $expiry = $mandate->certificate_expiry_date?->format('Y-m-d');
        $result = ['mandate_id' => (string) $mandate->id, 'certificate_expiry_date' => $expiry];

        if ($requiresCertificate) {
            if ($mandate->certificate_of_taxable_status_path === null || $mandate->certificate_of_taxable_status_path === '') {
                return ['status' => 'CERTIFICATE_MISSING'] + $result;
            }

            if (! $mandate->hasValidCertificate($moment)) {
                return ['status' => 'CERTIFICATE_EXPIRED'] + $result;
            }

            $warning = (int) config('foreign_vat_refund.certificate_warning_days', 60);

            if ($mandate->certificate_expiry_date !== null && $moment->diffInDays($mandate->certificate_expiry_date, false) <= $warning) {
                return ['status' => 'CERTIFICATE_EXPIRING'] + $result;
            }
        }

        return ['status' => 'VALID'] + $result;
    }

    /**
     * KPI cards and the monitor of the dashboard.
     *
     * @return array<string, mixed>
     */
    public function dashboard(string $tenantId, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $monitor = $this->overview($tenantId, $today);
        $accumulating = [];
        $ready = [];

        foreach ($monitor as $row) {
            $currency = (string) $row['currency_code'];
            $accumulating[$currency] = Decimal::add($accumulating[$currency] ?? '0', (string) $row['accumulated_amount']);

            if ($row['eligibility_status'] === self::MET && $row['alert_level'] !== self::EXPIRED) {
                $ready[$currency] = Decimal::add($ready[$currency] ?? '0', (string) $row['accumulated_amount']);
            }
        }

        $awaiting = [];
        $refunded = [];

        foreach (VatRefundClaim::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')->get() as $claim) {
            $currency = $claim->currency_code;

            if (in_array($claim->status, [ClaimStatus::Submitted, ClaimStatus::AdditionalInfoRequested, ClaimStatus::Approved, ClaimStatus::PartiallyApproved], true)) {
                $open = Decimal::sub((string) $claim->total_requested_amount, Decimal::add((string) $claim->total_rejected_amount, (string) $claim->total_refunded_amount));
                $awaiting[$currency] = Decimal::add($awaiting[$currency] ?? '0', $open);
            }

            if ($claim->refunded_on !== null && (int) $claim->refunded_on->format('Y') === $today->year) {
                $refunded[$currency] = Decimal::add($refunded[$currency] ?? '0', (string) $claim->total_refunded_amount);
            }
        }

        return [
            'kpis' => [
                'accumulating' => $this->amounts($accumulating),
                'ready_to_claim' => $this->amounts($ready),
                'submitted_awaiting_refund' => $this->amounts($awaiting),
                'refunded_ytd' => $this->amounts($refunded),
            ],
            'monitor' => $monitor,
            'generated_on' => $today->format('Y-m-d'),
        ];
    }

    /**
     * @param  Collection<int, ForeignVatLedgerEntry>  $items
     * @return array<string, mixed>
     */
    private function row(string $tenantId, CountryVatRefundRule $procedure, string $country, int $year, Collection $items, CarbonImmutable $today): array
    {
        $currency = $procedure->currency_code;
        $matching = $items->filter(fn (ForeignVatLedgerEntry $entry): bool => $entry->currency_code === $currency);
        $accumulated = $matching->reduce(fn (string $sum, ForeignVatLedgerEntry $entry): string => Decimal::add($sum, (string) $entry->recoverable_vat_amount), '0');
        $annual = $procedure->min_annual_amount === null ? null : (string) $procedure->min_annual_amount;
        $quarterly = $procedure->min_quarterly_amount === null ? null : (string) $procedure->min_quarterly_amount;
        $deadline = $this->deadlineOf($procedure, $year);
        $days = (int) $today->diffInDays($deadline, false);
        $quarters = [];

        if ($quarterly !== null) {
            for ($quarter = 1; $quarter <= 4; $quarter++) {
                $amount = $matching->where('fiscal_quarter', $quarter)->reduce(fn (string $sum, ForeignVatLedgerEntry $entry): string => Decimal::add($sum, (string) $entry->recoverable_vat_amount), '0');
                $quarters[] = ['quarter' => $quarter, 'amount' => Decimal::round2($amount), 'threshold' => $quarterly, 'progress_percent' => $this->progress($amount, $quarterly), 'eligibility_status' => $this->status($amount, $quarterly)];
            }
        }

        return [
            'country_code' => $country,
            'fiscal_year' => $year,
            'regime_type' => $procedure->regime_type->value,
            'currency_code' => $currency,
            'has_reciprocity' => $procedure->has_reciprocity,
            'items_count' => $matching->count(),
            'other_currency_items' => $items->count() - $matching->count(),
            'accumulated_amount' => Decimal::round2($accumulated),
            'threshold_annual' => $annual,
            'threshold_quarterly' => $quarterly,
            'progress_percent' => $this->progress($accumulated, $annual),
            'eligibility_status' => $this->status($accumulated, $annual),
            'quarters' => $quarters,
            'deadline_date' => $deadline->format('Y-m-d'),
            'days_remaining' => $days,
            'alert_level' => $this->alertLevel($days),
            'requires_fiscal_representative' => $procedure->requires_fiscal_representative,
            'requires_taxable_status_certificate' => $procedure->requires_taxable_status_certificate,
            'representative' => $this->mandateStatus($tenantId, $country, $today, $procedure->requires_fiscal_representative, $procedure->requires_taxable_status_certificate),
        ];
    }

    private function status(string $amount, ?string $threshold): string
    {
        return $threshold === null || Decimal::compare($amount, $threshold) >= 0 ? self::MET : self::BELOW;
    }

    private function progress(string $amount, ?string $threshold): string
    {
        if ($threshold === null || ! Decimal::isPositive($threshold)) {
            return '100.00';
        }

        $percent = Decimal::div(Decimal::mul($amount, '100'), $threshold);

        return Decimal::compare($percent, '100') > 0 ? '100.00' : Decimal::round2($percent);
    }

    /**
     * @param  array<string, string>  $byCurrency
     * @return list<array{currency: string, amount: string}>
     */
    private function amounts(array $byCurrency): array
    {
        ksort($byCurrency);

        return array_map(static fn (string $currency, string $amount): array => ['currency' => $currency, 'amount' => Decimal::round2($amount)], array_keys($byCurrency), array_values($byCurrency));
    }
}
