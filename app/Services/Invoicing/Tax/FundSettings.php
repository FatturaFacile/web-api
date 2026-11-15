<?php

declare(strict_types=1);

namespace App\Services\Invoicing\Tax;

/**
 * Social security fund (cassa previdenziale / rivalsa) applied on the fund-eligible lines.
 */
final readonly class FundSettings
{
    public function __construct(
        public string $type,
        public string|int|float $percent,
        public string|int|float|null $vatRate = null,
        public ?string $natura = null,
        public bool $withholdingApplies = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $vatRate = $data['vat_rate'] ?? null;
        $natura = $data['natura'] ?? null;

        return new self(
            type: strtoupper((string) ($data['type'] ?? 'TC22')),
            percent: self::number($data['percent'] ?? '0'),
            vatRate: $vatRate === null || $vatRate === '' ? null : self::number($vatRate),
            natura: is_string($natura) && $natura !== '' ? strtoupper($natura) : null,
            withholdingApplies: (bool) ($data['withholding_applies'] ?? false),
        );
    }

    private static function number(mixed $value): string|int|float
    {
        return is_int($value) || is_float($value) ? $value : (string) $value;
    }
}
