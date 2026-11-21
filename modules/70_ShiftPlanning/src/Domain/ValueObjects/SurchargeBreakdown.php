<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\ValueObjects;

/**
 * The minutes of a shift that attract a surcharge, and what the labour law of the jurisdiction grants for them. Payroll
 * (Mod 076 Swiss, Mod 077 Italian) and Time & Attendance (Mod 072) consume this payload as it is. Percentages are null when
 * the collective agreement, not the statute, decides (Italy): the minutes are then the only fact exported.
 */
final readonly class SurchargeBreakdown
{
    public function __construct(
        public int $workedMinutes,
        public int $nightMinutes = 0,
        public int $sundayMinutes = 0,
        public int $holidayMinutes = 0,
        public int $onCallMinutes = 0,
        public ?float $nightWageSurchargePercent = null,
        public int $nightTimeCompensationMinutes = 0,
        public ?float $sundayWageSurchargePercent = null,
        public ?float $holidayWageSurchargePercent = null,
        public ?float $onCallWageSurchargePercent = null,
    ) {}

    public static function none(int $workedMinutes = 0): self
    {
        return new self($workedMinutes);
    }

    /**
     * @return list<string> payroll tags: night, sunday, holiday, on_call
     */
    public function tags(): array
    {
        return array_values(array_filter([
            $this->nightMinutes > 0 ? 'night' : null,
            $this->sundayMinutes > 0 ? 'sunday' : null,
            $this->holidayMinutes > 0 ? 'holiday' : null,
            $this->onCallMinutes > 0 ? 'on_call' : null,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'worked_minutes' => $this->workedMinutes,
            'night_minutes' => $this->nightMinutes,
            'sunday_minutes' => $this->sundayMinutes,
            'holiday_minutes' => $this->holidayMinutes,
            'on_call_minutes' => $this->onCallMinutes,
            'night_wage_surcharge_percent' => $this->nightWageSurchargePercent,
            'night_time_compensation_minutes' => $this->nightTimeCompensationMinutes,
            'sunday_wage_surcharge_percent' => $this->sundayWageSurchargePercent,
            'holiday_wage_surcharge_percent' => $this->holidayWageSurchargePercent,
            'on_call_wage_surcharge_percent' => $this->onCallWageSurchargePercent,
            'tags' => $this->tags(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];

        return new self(
            (int) ($data['worked_minutes'] ?? 0),
            (int) ($data['night_minutes'] ?? 0),
            (int) ($data['sunday_minutes'] ?? 0),
            (int) ($data['holiday_minutes'] ?? 0),
            (int) ($data['on_call_minutes'] ?? 0),
            isset($data['night_wage_surcharge_percent']) ? (float) $data['night_wage_surcharge_percent'] : null,
            (int) ($data['night_time_compensation_minutes'] ?? 0),
            isset($data['sunday_wage_surcharge_percent']) ? (float) $data['sunday_wage_surcharge_percent'] : null,
            isset($data['holiday_wage_surcharge_percent']) ? (float) $data['holiday_wage_surcharge_percent'] : null,
            isset($data['on_call_wage_surcharge_percent']) ? (float) $data['on_call_wage_surcharge_percent'] : null,
        );
    }
}
