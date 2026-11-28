<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Domain\ValueObjects;

use Carbon\CarbonImmutable;
use Modules\TimeTravel\Support\Instant;

/**
 * A point of the bi-temporal plane: what was economically true (valid time) as far as the system knew at a given moment (known time).
 */
final readonly class BiTemporalCoordinate
{
    public CarbonImmutable $validAt;

    public CarbonImmutable $knownAt;

    public function __construct(CarbonImmutable $validAt, CarbonImmutable $knownAt)
    {
        $this->validAt = $validAt->utc();
        $this->knownAt = $knownAt->utc();
    }

    public static function of(string|CarbonImmutable $validAt, string|CarbonImmutable $knownAt): self
    {
        return new self(Instant::from($validAt), Instant::from($knownAt));
    }

    /**
     * @return array{valid_at: string, known_at: string}
     */
    public function toArray(): array
    {
        return ['valid_at' => Instant::iso($this->validAt), 'known_at' => Instant::iso($this->knownAt)];
    }
}
