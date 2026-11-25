<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Domain\Statement;

/**
 * One line of an official statement scheme (Art. 2424 / 2425 c.c., CO Art. 959a / 959b, OIC 10 / FER 4). A line either sums
 * its children (`parentCode` points upwards), or is the result of a formula, or is a leaf that receives ledger amounts.
 */
final readonly class LineDefinition
{
    /**
     * @param  list<string>  $schemes  Schemes of the standard that show the line ("ord", "abb", "micro").
     * @param  list<array{0: string, 1: int}>|null  $formula  Components [code, sign] of a computed line.
     * @param  array<string, string>  $labels  Label per locale (it, en, de, fr).
     */
    public function __construct(
        public string $code,
        public string $family,
        public string $statement,
        public string $variant,
        public ?string $parentCode,
        public int $sign,
        public string $side,
        public int $sortOrder,
        public array $schemes,
        public ?array $formula,
        public ?string $cfGroup,
        public ?string $xbrlElement,
        public bool $isRounding,
        public array $labels,
    ) {}

    public function label(string $locale): string
    {
        return $this->labels[$locale] ?? $this->labels['en'] ?? $this->code;
    }

    public function inScheme(string $scheme): bool
    {
        return in_array($scheme, $this->schemes, true);
    }
}
