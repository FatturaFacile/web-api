<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Domain\ValueObjects;

final readonly class Gs1VariableWeightParseResult
{
    public function __construct(
        public string $rawBarcode,
        public string $format,
        public ?string $gtin,
        public ?string $skuCandidate,
        public ?float $netWeightKg,
        public ?float $variablePriceAmount,
        public ?string $lotNumber,
        public ?string $expiryDate,
        public ?string $matchedProductId,
        public ?string $matchedProductSku,
        public ?string $matchedProductName,
        public ?float $unitPrice,
        public ?float $computedLineTotal,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'raw_barcode' => $this->rawBarcode,
            'format' => $this->format,
            'gtin' => $this->gtin,
            'sku_candidate' => $this->skuCandidate,
            'net_weight_kg' => $this->netWeightKg,
            'variable_price_amount' => $this->variablePriceAmount,
            'lot_number' => $this->lotNumber,
            'expiry_date' => $this->expiryDate,
            'matched_product_id' => $this->matchedProductId,
            'matched_product_sku' => $this->matchedProductSku,
            'matched_product_name' => $this->matchedProductName,
            'unit_price' => $this->unitPrice,
            'computed_line_total' => $this->computedLineTotal,
        ];
    }
}
