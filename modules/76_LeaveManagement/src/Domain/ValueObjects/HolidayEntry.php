<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Domain\ValueObjects;

/**
 * Single public or patron saint holiday entry with Sunday-falling rule metadata.
 */
final readonly class HolidayEntry
{
    public function __construct(
        public string $date,
        public string $name,
        public string $scope,
        public bool $isSunday = false,
        public bool $isHalfDay = false,
        public bool $paidCompensationApplicable = false,
    ) {}

    /**
     * @return array{
     *     date: string,
     *     name: string,
     *     scope: string,
     *     is_sunday: bool,
     *     is_half_day: bool,
     *     paid_compensation_applicable: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'name' => $this->name,
            'scope' => $this->scope,
            'is_sunday' => $this->isSunday,
            'is_half_day' => $this->isHalfDay,
            'paid_compensation_applicable' => $this->paidCompensationApplicable,
        ];
    }
}
