<?php

declare(strict_types=1);

namespace App\Services\Invoicing\Tax;

/**
 * One invoice line as seen by the fiscal calculator. Numbers are decimal strings (or ints/floats from a request).
 */
final readonly class FiscalLine
{
    public function __construct(
        public string|int|float $quantity,
        public string|int|float $unitPrice,
        public string|int|float $taxRate,
        public string|int|float $discountPercent = 0,
        public ?string $natura = null,
        public bool $withholdingApplies = true,
        public bool $fundApplies = true,
    ) {}

    /**
     * @param  array<string, mixed>  $item
     */
    public static function fromArray(array $item): self
    {
        $natura = $item['natura'] ?? null;

        return new self(
            quantity: self::number($item['quantity'] ?? 1),
            unitPrice: self::number($item['unit_price'] ?? 0),
            taxRate: self::number($item['tax_rate'] ?? 0),
            discountPercent: self::number($item['discount_percentage'] ?? 0),
            natura: is_string($natura) && $natura !== '' ? strtoupper($natura) : null,
            withholdingApplies: (bool) ($item['withholding_applies'] ?? true),
            fundApplies: (bool) ($item['fund_applies'] ?? true),
        );
    }

    private static function number(mixed $value): string|int|float
    {
        return is_int($value) || is_float($value) ? $value : (string) $value;
    }
}
