<?php

declare(strict_types=1);

namespace Modules\Forecasting\Domain\Services;

use DateTimeImmutable;
use Modules\Forecasting\Domain\Enums\JurisdictionCode;
use Modules\Forecasting\Domain\ValueObjects\StatutoryCashEvent;
use Modules\Forecasting\Domain\ValueObjects\StatutoryProfile;

/**
 * Recurring tax, social security and payroll outflows of a jurisdiction that a projection must not miss.
 *
 * Italy: F24 on the 16th, quarterly VAT (LIPE) on May 16, Aug 20, Nov 16 and Mar 16, the VAT advance on Dec 27, the income
 * tax advances on Jun 30 and Nov 30, the 13th salary in December and the 14th in June.
 * Switzerland: quarterly MWST at the end of the second month after the quarter, AVS/LPP installments and the year-end
 * reconciliation, and the 13th monthly salary in November or December.
 * A date on a weekend rolls to the next Monday; public holidays are not modelled.
 */
final class StatutoryCashOutflowCalendarService
{
    /**
     * @return list<StatutoryCashEvent> ordered by cash date, with the events whose cash date is within [$from, $to]
     */
    public function eventsBetween(JurisdictionCode $jurisdiction, DateTimeImmutable $from, DateTimeImmutable $to, StatutoryProfile $profile): array
    {
        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0);
        $events = [];

        for ($year = (int) $from->format('Y') - 1; $year <= (int) $to->format('Y') + 1; $year++) {
            $candidates = $jurisdiction === JurisdictionCode::It ? $this->italianEvents($year, $profile) : $this->swissEvents($year, $profile);

            foreach ($candidates as $event) {
                if ($event->amount > 0.0 && $event->date >= $from && $event->date <= $to) {
                    $events[] = $event;
                }
            }
        }

        usort($events, static fn (StatutoryCashEvent $a, StatutoryCashEvent $b): int => [$a->date, $a->code] <=> [$b->date, $b->code]);

        return $events;
    }

    /**
     * @return list<StatutoryCashEvent>
     */
    private function italianEvents(int $year, StatutoryProfile $profile): array
    {
        $events = [];

        foreach (range(1, 12) as $month) {
            $events[] = $this->event($year, $month, 16, 'F24', StatutoryCashEvent::CategoryTax, $profile->monthlyWithholdingAndContributions);
        }

        $events[] = $this->event($year, 5, 16, 'LIPE_Q1', StatutoryCashEvent::CategoryTax, $profile->quarterlyVat);
        $events[] = $this->event($year, 8, 20, 'LIPE_Q2', StatutoryCashEvent::CategoryTax, $profile->quarterlyVat);
        $events[] = $this->event($year, 11, 16, 'LIPE_Q3', StatutoryCashEvent::CategoryTax, $profile->quarterlyVat);
        $events[] = $this->event($year, 3, 16, 'LIPE_Q4', StatutoryCashEvent::CategoryTax, $profile->quarterlyVat);
        $events[] = $this->event($year, 12, 27, 'IVA_ADVANCE', StatutoryCashEvent::CategoryTax, $profile->vatAdvance);
        $events[] = $this->event($year, 6, 30, 'TAX_ADVANCE_JUN', StatutoryCashEvent::CategoryTax, $profile->annualIncomeTaxAdvance * 0.4);
        $events[] = $this->event($year, 11, 30, 'TAX_ADVANCE_NOV', StatutoryCashEvent::CategoryTax, $profile->annualIncomeTaxAdvance * 0.6);

        if ($profile->paysThirteenthSalary) {
            $events[] = $this->event($year, 12, 20, 'SALARY_13TH', StatutoryCashEvent::CategoryPayroll, $profile->monthlyPayroll);
        }

        if ($profile->paysFourteenthSalary) {
            $events[] = $this->event($year, 6, 20, 'SALARY_14TH', StatutoryCashEvent::CategoryPayroll, $profile->monthlyPayroll);
        }

        return $events;
    }

    /**
     * @return list<StatutoryCashEvent>
     */
    private function swissEvents(int $year, StatutoryProfile $profile): array
    {
        $events = [
            $this->event($year, 5, 31, 'MWST_Q1', StatutoryCashEvent::CategoryTax, $profile->quarterlyVat),
            $this->event($year, 8, 31, 'MWST_Q2', StatutoryCashEvent::CategoryTax, $profile->quarterlyVat),
            $this->event($year, 11, 30, 'MWST_Q3', StatutoryCashEvent::CategoryTax, $profile->quarterlyVat),
            $this->event($year, 2, $this->daysInFebruary($year), 'MWST_Q4', StatutoryCashEvent::CategoryTax, $profile->quarterlyVat),
        ];

        foreach ([1, 4, 7, 10] as $month) {
            $events[] = $this->event($year, $month, 10, 'AVS_LPP', StatutoryCashEvent::CategorySocial, $profile->quarterlySocialSecurity);
        }

        $events[] = $this->event($year, 1, 31, 'AVS_LPP_RECONCILIATION', StatutoryCashEvent::CategorySocial, $profile->socialSecurityReconciliation);

        if ($profile->paysThirteenthSalary) {
            $events[] = $this->event($year, $profile->swissThirteenthSalaryMonth, 25, 'SALARY_13TH', StatutoryCashEvent::CategoryPayroll, $profile->monthlyPayroll);
        }

        return $events;
    }

    private function event(int $year, int $month, int $day, string $code, string $category, float $amount): StatutoryCashEvent
    {
        $nominal = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));

        return new StatutoryCashEvent($nominal, $this->nextBusinessDay($nominal), $code, $category, $amount);
    }

    private function nextBusinessDay(DateTimeImmutable $date): DateTimeImmutable
    {
        return match ((int) $date->format('N')) {
            6 => $date->modify('+2 days'),
            7 => $date->modify('+1 day'),
            default => $date,
        };
    }

    private function daysInFebruary(int $year): int
    {
        return (int) (new DateTimeImmutable(sprintf('%04d-02-01', $year)))->format('t');
    }
}
