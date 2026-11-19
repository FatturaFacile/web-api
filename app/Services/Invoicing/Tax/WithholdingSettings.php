<?php

declare(strict_types=1);

namespace App\Services\Invoicing\Tax;

/**
 * Withholding tax (ritenuta d'acconto): rate applied on `taxablePercent` of the withholding-eligible lines.
 */
final readonly class WithholdingSettings
{
    public function __construct(
        public string $type,
        public string $reason,
        public string|int|float $rate,
        public string|int|float $taxablePercent = 100,
        public bool $includesFund = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: strtoupper((string) ($data['type'] ?? config('italian_tax.withholding.default_type', 'RT01'))),
            reason: strtoupper((string) ($data['reason'] ?? config('italian_tax.withholding.default_reason', 'A'))),
            rate: self::number($data['rate'] ?? '0'),
            taxablePercent: self::number($data['taxable_percent'] ?? '100'),
            includesFund: (bool) ($data['includes_fund'] ?? false),
        );
    }

    private static function number(mixed $value): string|int|float
    {
        return is_int($value) || is_float($value) ? $value : (string) $value;
    }
}
