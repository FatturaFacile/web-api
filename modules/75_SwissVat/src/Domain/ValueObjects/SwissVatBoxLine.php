<?php

declare(strict_types=1);

namespace Modules\SwissVat\Domain\ValueObjects;

/**
 * Represents one official AFC / ESTV Form 050/051 box line.
 */
final readonly class SwissVatBoxLine
{
    public function __construct(
        public string $code,
        public string $section,
        public string $labelKey,
        public string $amount,
        public ?string $baseAmount = null,
        public ?string $ratePercent = null,
        public ?string $taxAmount = null,
        public int $sourceCount = 0,
        public ?string $epochBoxAlias = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'section' => $this->section,
            'label_key' => $this->labelKey,
            'label' => __($this->labelKey),
            'amount' => $this->amount,
            'base_amount' => $this->baseAmount,
            'rate_percent' => $this->ratePercent,
            'tax_amount' => $this->taxAmount,
            'source_count' => $this->sourceCount,
            'epoch_box_alias' => $this->epochBoxAlias,
        ];
    }
}
