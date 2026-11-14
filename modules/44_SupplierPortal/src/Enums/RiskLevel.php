<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Enums;

/**
 * Risk flag of a supplier, from the points the risk rules add up.
 */
enum RiskLevel: string
{
    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 100 => self::Critical,
            $score >= 50 => self::High,
            $score >= 20 => self::Medium,
            $score > 0 => self::Low,
            default => self::None,
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Critical => 4,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $level): string => $level->value, self::cases());
    }
}
