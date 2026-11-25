<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\ValueObjects;

use Modules\PassengerTransport\Domain\Support\Dec;

/**
 * The split of a fare in taxable and non-taxable lines, with the shares of the kilometres of each territory.
 */
final readonly class VatBreakdown
{
    /**
     * @param  list<VatLine>  $lines
     */
    public function __construct(
        public string $currency,
        public array $lines,
        public string $domesticShare,
        public string $foreignShare,
    ) {}

    public function totalNet(): string
    {
        return Dec::round(array_reduce($this->lines, static fn (string $sum, VatLine $line): string => Dec::add($sum, $line->net), '0'));
    }

    public function totalTax(): string
    {
        return Dec::round(array_reduce($this->lines, static fn (string $sum, VatLine $line): string => Dec::add($sum, $line->tax), '0'));
    }

    public function totalGross(): string
    {
        return Dec::round(Dec::add($this->totalNet(), $this->totalTax()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'lines' => array_map(static fn (VatLine $line): array => $line->toArray(), $this->lines),
            'domestic_share' => $this->domesticShare,
            'foreign_share' => $this->foreignShare,
            'total_net' => $this->totalNet(),
            'total_tax' => $this->totalTax(),
            'total_gross' => $this->totalGross(),
        ];
    }
}
