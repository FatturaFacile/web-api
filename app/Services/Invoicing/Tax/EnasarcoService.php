<?php

declare(strict_types=1);

namespace App\Services\Invoicing\Tax;

use App\Exceptions\FiscalRuleException;
use App\Models\EnasarcoAnnualLimit;
use App\Models\Invoice;
use App\Support\Money;

/**
 * ENASARCO figures for an agent (the tenant) towards one principal (the invoiced contact) in a calendar year:
 * yearly rate and ceiling, commissions already invoiced, remaining ceiling and the year-end minimum shortfall.
 */
class EnasarcoService
{
    /**
     * Rate, annual minimum and commission ceiling of a year: tenant row, then shared row, then config.
     *
     * @return array{rate: string, annual_minimum: string, annual_maximum_commission: string}
     *
     * @throws FiscalRuleException when the year has no configured rate
     */
    public function limitsFor(int $year): array
    {
        $rows = EnasarcoAnnualLimit::query()->where('year', $year)->get();
        $row = $rows->first(fn (EnasarcoAnnualLimit $limit): bool => $limit->tenant_id !== null) ?? $rows->first();

        if ($row !== null) {
            return [
                'rate' => (string) $row->rate,
                'annual_minimum' => (string) $row->annual_minimum,
                'annual_maximum_commission' => (string) $row->annual_maximum_commission,
            ];
        }

        $configured = config("italian_tax.enasarco.years.{$year}");

        if (! is_array($configured) || ! isset($configured['rate'], $configured['annual_maximum_commission'])) {
            throw new FiscalRuleException('enasarco_rate_not_configured', 'enasarco', ['year' => $year]);
        }

        return [
            'rate' => (string) $configured['rate'],
            'annual_minimum' => (string) ($configured['annual_minimum'] ?? '0.00'),
            'annual_maximum_commission' => (string) $configured['annual_maximum_commission'],
        ];
    }

    /**
     * Commissions subject to ENASARCO already invoiced to the contact in the year, in cents (credit notes subtract).
     */
    public function ytdCommissionsCents(string $contactId, int $year, ?string $excludeInvoiceId = null): int
    {
        return $this->sumForYear($contactId, $year, 'enasarco_commissions', $excludeInvoiceId);
    }

    /**
     * ENASARCO contributions already withheld for the contact in the year, in cents (credit notes subtract).
     */
    public function ytdContributionsCents(string $contactId, int $year): int
    {
        return $this->sumForYear($contactId, $year, 'enasarco_amount');
    }

    /**
     * Settings for a new invoice to the contact dated in `$year`.
     *
     * @param  string|int|float|null  $rateOverride  explicit rate from the request, if any
     */
    public function settingsFor(string $contactId, int $year, string|int|float|null $rateOverride = null, ?string $reason = null): EnasarcoSettings
    {
        $limits = $this->limitsFor($year);

        return new EnasarcoSettings(
            rate: $rateOverride !== null ? Money::fromScaled(Money::toScaled($rateOverride, 2), 2) : $limits['rate'],
            annualMaximumCommission: $limits['annual_maximum_commission'],
            ytdCommissions: Money::fromCents($this->ytdCommissionsCents($contactId, $year)),
            reason: $reason ?? (string) config('italian_tax.enasarco.default_reason', 'R'),
            annualMinimumContribution: $limits['annual_minimum'],
            ytdContribution: Money::fromCents($this->ytdContributionsCents($contactId, $year)),
        );
    }

    /**
     * Yearly status for the contact: commissions, ceiling, minimum and shortfall (decimal strings).
     *
     * @return array{contact_id: string, year: int, rate: string, ytd_commissions: string, annual_maximum: string, remaining_cap: string, annual_minimum: string, ytd_contributions: string, shortfall: string}
     */
    public function status(string $contactId, int $year): array
    {
        $limits = $this->limitsFor($year);
        $commissions = $this->ytdCommissionsCents($contactId, $year);
        $contributions = $this->ytdContributionsCents($contactId, $year);
        $maximum = Money::toCents($limits['annual_maximum_commission']);
        $minimum = Money::toCents($limits['annual_minimum']);

        return [
            'contact_id' => $contactId,
            'year' => $year,
            'rate' => $limits['rate'],
            'ytd_commissions' => Money::fromCents($commissions),
            'annual_maximum' => Money::fromCents($maximum),
            'remaining_cap' => Money::fromCents(max(0, $maximum - $commissions)),
            'annual_minimum' => Money::fromCents($minimum),
            'ytd_contributions' => Money::fromCents($contributions),
            'shortfall' => Money::fromCents(max(0, $minimum - $contributions)),
        ];
    }

    private function sumForYear(string $contactId, int $year, string $column, ?string $excludeInvoiceId = null): int
    {
        if ($contactId === '') {
            return 0;
        }

        $invoices = Invoice::query()
            ->where('contact_id', $contactId)
            ->whereDate('date', '>=', sprintf('%04d-01-01', $year))
            ->whereDate('date', '<=', sprintf('%04d-12-31', $year))
            ->where(function ($query) use ($column): void {
                $query->whereNotNull($column);
                if ($column === 'enasarco_commissions') {
                    $query->orWhereNotNull('enasarco_base');
                }
            })
            ->when($excludeInvoiceId !== null, fn ($query) => $query->whereKeyNot($excludeInvoiceId))
            ->get($column === 'enasarco_commissions' ? ['id', 'invoice_type', 'enasarco_commissions', 'enasarco_base'] : ['id', 'invoice_type', $column]);

        $total = 0;

        foreach ($invoices as $invoice) {
            $raw = $invoice->getAttribute($column);
            if ($raw === null && $column === 'enasarco_commissions') {
                $raw = $invoice->getAttribute('enasarco_base');
            }
            $amount = Money::toCents((string) ($raw ?? '0'));
            $total += $invoice->invoice_type === 'credit_note' ? -$amount : $amount;
        }

        return $total;
    }
}
