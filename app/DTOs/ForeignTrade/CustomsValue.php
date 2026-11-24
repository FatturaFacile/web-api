<?php

declare(strict_types=1);

namespace App\DTOs\ForeignTrade;

final readonly class CustomsValue
{
    public function __construct(
        public string $incoterm,
        public float $goodsValue,
        public float $freightAdded,
        public float $insuranceAdded,
        public float $customsValue,
        public float $statisticalValue,
    ) {}

    /**
     * @return array<string, float|string>
     */
    public function toArray(): array
    {
        return [
            'incoterm' => $this->incoterm,
            'goods_value' => $this->goodsValue,
            'freight_added' => $this->freightAdded,
            'insurance_added' => $this->insuranceAdded,
            'customs_value' => $this->customsValue,
            'statistical_value' => $this->statisticalValue,
        ];
    }
}
