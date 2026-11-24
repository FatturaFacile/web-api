<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Domain\ValueObjects;

/**
 * Structured payroll indemnity and carenza/waiting-period breakdown for IT (INPS/INAIL/L.104)
 * and CH (IJM/KTG, LAINF/UVG/SUVA, IPG/EO).
 */
final readonly class IndemnityBreakdown
{
    public function __construct(
        public string $country,
        public string $regime,
        public int $totalCalendarDays,
        public int $employerWaitingDays,
        public float $employerIndemnityPercent,
        public int $tier1InsurerDays,
        public float $tier1InsurerPercent,
        public int $tier2InsurerDays,
        public float $tier2InsurerPercent,
        public string $notes,
    ) {}

    /**
     * @return array{
     *     country: string,
     *     regime: string,
     *     total_calendar_days: int,
     *     employer_waiting_days: int,
     *     employer_indemnity_percent: float,
     *     tier1_insurer_days: int,
     *     tier1_insurer_percent: float,
     *     tier2_insurer_days: int,
     *     tier2_insurer_percent: float,
     *     notes: string
     * }
     */
    public function toArray(): array
    {
        return [
            'country' => $this->country,
            'regime' => $this->regime,
            'total_calendar_days' => $this->totalCalendarDays,
            'employer_waiting_days' => $this->employerWaitingDays,
            'employer_indemnity_percent' => $this->employerIndemnityPercent,
            'tier1_insurer_days' => $this->tier1InsurerDays,
            'tier1_insurer_percent' => $this->tier1InsurerPercent,
            'tier2_insurer_days' => $this->tier2InsurerDays,
            'tier2_insurer_percent' => $this->tier2InsurerPercent,
            'notes' => $this->notes,
        ];
    }
}
