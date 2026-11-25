<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Domain\ValueObjects;

/**
 * What an OCR provider read from a receipt.
 */
final readonly class OcrResult
{
    /**
     * @param  list<array{description: string, amount: string}>  $lines
     */
    public function __construct(
        public string $provider,
        public float $confidence,
        public ?string $merchantName = null,
        public ?string $expenseDate = null,
        public ?string $currency = null,
        public ?string $totalAmount = null,
        public ?string $vatAmount = null,
        public ?string $vatRate = null,
        public array $lines = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'confidence' => round($this->confidence, 4),
            'merchant_name' => $this->merchantName,
            'expense_date' => $this->expenseDate,
            'currency' => $this->currency,
            'total_amount' => $this->totalAmount,
            'vat_amount' => $this->vatAmount,
            'vat_rate' => $this->vatRate,
            'lines' => $this->lines,
        ];
    }
}
