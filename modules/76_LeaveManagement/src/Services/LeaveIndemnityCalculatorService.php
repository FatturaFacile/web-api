<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Services;

use Carbon\CarbonImmutable;
use Modules\LeaveManagement\Domain\Enums\LeaveCountry;
use Modules\LeaveManagement\Domain\Enums\LeaveTypeCode;
use Modules\LeaveManagement\Domain\ValueObjects\IndemnityBreakdown;
use Modules\LeaveManagement\Models\LeaveEntitlementPolicy;

/**
 * Computes Italian (INPS carenza/brackets, INAIL, Legge 104) and Swiss (IJM/KTG Wartefrist,
 * LAINF/UVG/SUVA 80% day 3+, IPG/EO) indemnity & waiting-period breakdowns.
 */
final class LeaveIndemnityCalculatorService
{
    public function calculate(
        LeaveCountry $country,
        LeaveTypeCode $leaveType,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        ?LeaveEntitlementPolicy $policy = null,
        ?int $ijmKtgWaitingDaysOverride = null,
        ?bool $isOccupationalAccident = null,
    ): IndemnityBreakdown {
        $calendarDays = max(1, (int) $startDate->diffInDays($endDate) + 1);

        if ($country === LeaveCountry::IT) {
            return $this->calculateItalian($leaveType, $calendarDays, $policy);
        }

        return $this->calculateSwiss($leaveType, $calendarDays, $policy, $ijmKtgWaitingDaysOverride, $isOccupationalAccident);
    }

    private function calculateItalian(
        LeaveTypeCode $leaveType,
        int $calendarDays,
        ?LeaveEntitlementPolicy $policy,
    ): IndemnityBreakdown {
        if ($leaveType === LeaveTypeCode::SickLeave_Malattia) {
            $carenzaDays = min($calendarDays, $policy !== null ? $policy->waiting_period_days : 3);
            $remainingAfterCarenza = max(0, $calendarDays - $carenzaDays);
            $tier1Days = min($remainingAfterCarenza, 17); // days 4..20
            $tier2Days = max(0, $remainingAfterCarenza - $tier1Days); // days 21+

            return new IndemnityBreakdown(
                country: LeaveCountry::IT->value,
                regime: 'it_inps_sick',
                totalCalendarDays: $calendarDays,
                employerWaitingDays: $carenzaDays,
                employerIndemnityPercent: $policy !== null ? (float) $policy->employer_indemnity_percent : 100.0,
                tier1InsurerDays: $tier1Days,
                tier1InsurerPercent: $policy !== null ? (float) $policy->insurer_indemnity_percent : 50.0,
                tier2InsurerDays: $tier2Days,
                tier2InsurerPercent: $policy !== null && $policy->insurer_indemnity_percent_tier2 !== null ? (float) $policy->insurer_indemnity_percent_tier2 : 66.66,
                notes: 'IT INPS Malattia: days 1-3 employer carenza, days 4-20 INPS 50%, days 21+ INPS 66.66% (subject to CCNL integration).',
            );
        }

        if ($leaveType === LeaveTypeCode::Accident_Infortunio_SUVA_INAIL) {
            // IT INAIL: day 1 100% employer, days 2-4 60% employer carenza, days 5-90 60% INAIL, days 91+ 75% INAIL
            $employerDays = min($calendarDays, 4);
            $afterCarenza = max(0, $calendarDays - 4);
            $tier1Days = min($afterCarenza, 86); // days 5..90
            $tier2Days = max(0, $afterCarenza - $tier1Days); // days 91+

            return new IndemnityBreakdown(
                country: LeaveCountry::IT->value,
                regime: 'it_inail_accident',
                totalCalendarDays: $calendarDays,
                employerWaitingDays: $employerDays,
                employerIndemnityPercent: 60.0,
                tier1InsurerDays: $tier1Days,
                tier1InsurerPercent: 60.0,
                tier2InsurerDays: $tier2Days,
                tier2InsurerPercent: 75.0,
                notes: 'IT INAIL: day 1 100% employer, days 2-4 employer 60% carenza, days 5-90 INAIL 60%, days 91+ INAIL 75%.',
            );
        }

        if ($leaveType === LeaveTypeCode::IT_Legge104_Permessi) {
            return new IndemnityBreakdown(
                country: LeaveCountry::IT->value,
                regime: 'it_legge_104',
                totalCalendarDays: $calendarDays,
                employerWaitingDays: 0,
                employerIndemnityPercent: 0.0,
                tier1InsurerDays: $calendarDays,
                tier1InsurerPercent: 100.0,
                tier2InsurerDays: 0,
                tier2InsurerPercent: 0.0,
                notes: 'Legge 104/1992: 100% INPS reimbursed paid permit (monthly cap 3 days / 18 hours).',
            );
        }

        if ($leaveType === LeaveTypeCode::MaternityPaternity_Congedo) {
            return new IndemnityBreakdown(
                country: LeaveCountry::IT->value,
                regime: 'it_inps_maternity',
                totalCalendarDays: $calendarDays,
                employerWaitingDays: 0,
                employerIndemnityPercent: 0.0,
                tier1InsurerDays: $calendarDays,
                tier1InsurerPercent: 80.0,
                tier2InsurerDays: 0,
                tier2InsurerPercent: 0.0,
                notes: 'IT INPS Maternity/Paternity: 80% INPS indemnity (100% for mandatory paternity 10 days).',
            );
        }

        $isPaid = $policy !== null ? $policy->is_paid : ($leaveType !== LeaveTypeCode::UnpaidLeave);

        return new IndemnityBreakdown(
            country: LeaveCountry::IT->value,
            regime: $isPaid ? 'standard_paid' : 'unpaid',
            totalCalendarDays: $calendarDays,
            employerWaitingDays: $calendarDays,
            employerIndemnityPercent: $isPaid ? 100.0 : 0.0,
            tier1InsurerDays: 0,
            tier1InsurerPercent: 0.0,
            tier2InsurerDays: 0,
            tier2InsurerPercent: 0.0,
            notes: $isPaid ? 'Standard employer-paid leave.' : 'Unpaid leave.',
        );
    }

    private function calculateSwiss(
        LeaveTypeCode $leaveType,
        int $calendarDays,
        ?LeaveEntitlementPolicy $policy,
        ?int $ijmKtgWaitingDaysOverride,
        ?bool $isOccupationalAccident,
    ): IndemnityBreakdown {
        if ($leaveType === LeaveTypeCode::SickLeave_Malattia) {
            $policyWait = $policy !== null ? $policy->waiting_period_days : 30;
            $wartefrist = max(0, $ijmKtgWaitingDaysOverride ?? $policyWait);
            $employerDays = min($calendarDays, $wartefrist);
            $insurerDays = max(0, $calendarDays - $employerDays);

            return new IndemnityBreakdown(
                country: LeaveCountry::CH->value,
                regime: 'ch_ijm_ktg',
                totalCalendarDays: $calendarDays,
                employerWaitingDays: $employerDays,
                employerIndemnityPercent: $policy !== null ? (float) $policy->employer_indemnity_percent : 100.0,
                tier1InsurerDays: $insurerDays,
                tier1InsurerPercent: $policy !== null ? (float) $policy->insurer_indemnity_percent : 80.0,
                tier2InsurerDays: 0,
                tier2InsurerPercent: 0.0,
                notes: "CH IJM/KTG: {$wartefrist}-day Wartefrist at employer burden, 80% IJM/KTG daily allowance thereafter.",
            );
        }

        if ($leaveType === LeaveTypeCode::Accident_Infortunio_SUVA_INAIL) {
            // LAINF/UVG/SUVA: employer pays 80% for days 1-2 (waiting days); SUVA pays 80% from day 3 onwards
            $employerWaitingDays = min($calendarDays, $policy !== null ? $policy->waiting_period_days : 2);
            $suvaDays = max(0, $calendarDays - $employerWaitingDays);
            $kindLabel = $isOccupationalAccident === false ? 'NBU (non-occupational)' : 'BU (occupational)';

            return new IndemnityBreakdown(
                country: LeaveCountry::CH->value,
                regime: 'ch_lainf_suva',
                totalCalendarDays: $calendarDays,
                employerWaitingDays: $employerWaitingDays,
                employerIndemnityPercent: 80.0,
                tier1InsurerDays: $suvaDays,
                tier1InsurerPercent: 80.0,
                tier2InsurerDays: 0,
                tier2InsurerPercent: 0.0,
                notes: "CH LAINF/UVG/SUVA ({$kindLabel}): 80% employer on days 1-2, 80% SUVA/UVG indemnity starting from day 3.",
            );
        }

        if ($leaveType === LeaveTypeCode::CH_MilitaryCivilService_EO_APG || $leaveType === LeaveTypeCode::MaternityPaternity_Congedo) {
            return new IndemnityBreakdown(
                country: LeaveCountry::CH->value,
                regime: 'ch_ipg_eo',
                totalCalendarDays: $calendarDays,
                employerWaitingDays: 0,
                employerIndemnityPercent: 0.0,
                tier1InsurerDays: $calendarDays,
                tier1InsurerPercent: 80.0,
                tier2InsurerDays: 0,
                tier2InsurerPercent: 0.0,
                notes: 'CH IPG/EO/APG: 80% daily compensation via compensation fund (Ausgleichskasse / Cassa di compensazione).',
            );
        }

        $isPaid = $policy !== null ? $policy->is_paid : ($leaveType !== LeaveTypeCode::UnpaidLeave);

        return new IndemnityBreakdown(
            country: LeaveCountry::CH->value,
            regime: $isPaid ? 'standard_paid' : 'unpaid',
            totalCalendarDays: $calendarDays,
            employerWaitingDays: $calendarDays,
            employerIndemnityPercent: $isPaid ? 100.0 : 0.0,
            tier1InsurerDays: 0,
            tier1InsurerPercent: 0.0,
            tier2InsurerDays: 0,
            tier2InsurerPercent: 0.0,
            notes: $isPaid ? 'Standard employer-paid leave.' : 'Unpaid leave.',
        );
    }
}
