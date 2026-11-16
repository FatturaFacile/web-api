<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

final readonly class ItalyComplianceViolation
{
    /**
     * @param  string  $type  'rest_11h' | 'weekly_48h'
     * @param  string  $date  Y-m-d of the violated day or week start
     * @param  float  $actual  actual hours in the violation period
     * @param  float  $limit  the legal limit that was exceeded or not met
     */
    public function __construct(
        public readonly string $type,
        public readonly string $userId,
        public readonly string $date,
        public readonly float $actual,
        public readonly float $limit,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'user_id' => $this->userId,
            'date' => $this->date,
            'actual_hours' => $this->actual,
            'limit_hours' => $this->limit,
        ];
    }
}
