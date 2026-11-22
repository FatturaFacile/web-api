<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Domain\ValueObjects;

use Modules\ResourcePlanning\Domain\Enums\SaturationBand;

/**
 * Capacity and bookings of one employee over one period (a day or a Monday-to-Sunday week).
 */
final readonly class LoadCell
{
    public function __construct(
        public string $periodStart,
        public float $contractualHours,
        public float $leaveAndHolidayHours,
        public float $netHours,
        public float $hardHours,
        public float $softHours,
        public float $billableHours,
        public float $billableCommittedHours,
        public float $internalHours,
    ) {}

    public function bookedHours(): float
    {
        return $this->hardHours + $this->softHours;
    }

    public function loadPercent(): float
    {
        return SaturationBand::loadPercent($this->netHours, $this->bookedHours());
    }

    public function band(): SaturationBand
    {
        return SaturationBand::fromLoad($this->netHours, $this->bookedHours(), $this->loadPercent());
    }

    public function isOverloaded(): bool
    {
        return $this->loadPercent() > SaturationBand::RedAbove;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'period_start' => $this->periodStart,
            'contractual_hours' => round($this->contractualHours, 2),
            'leave_and_holiday_hours' => round($this->leaveAndHolidayHours, 2),
            'net_available_hours' => round($this->netHours, 2),
            'hard_booked_hours' => round($this->hardHours, 2),
            'soft_booked_hours' => round($this->softHours, 2),
            'billable_booked_hours' => round($this->billableHours, 2),
            'internal_booked_hours' => round($this->internalHours, 2),
            'load_percent' => $this->loadPercent(),
            'band' => $this->band()->value,
            'is_overloaded' => $this->isOverloaded(),
        ];
    }
}
