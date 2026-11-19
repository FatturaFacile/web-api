<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Erasure;

use Carbon\CarbonImmutable;

/**
 * Result of the statutory fiscal lock inspection of one data subject.
 */
final readonly class FiscalLockReport
{
    /**
     * @param  list<array{table: string, module: string, rows: int, retention_ends_at: string}>  $records  protected record groups
     */
    public function __construct(
        public array $records,
        public ?CarbonImmutable $retentionEndsAt,
    ) {}

    public function isLocked(): bool
    {
        return $this->records !== [];
    }

    public function protectedRowCount(): int
    {
        return array_sum(array_column($this->records, 'rows'));
    }

    /**
     * @return array{is_locked: bool, retention_ends_at: string|null, records: list<array{table: string, module: string, rows: int, retention_ends_at: string}>}
     */
    public function toArray(): array
    {
        return [
            'is_locked' => $this->isLocked(),
            'retention_ends_at' => $this->retentionEndsAt?->toDateString(),
            'records' => $this->records,
        ];
    }
}
