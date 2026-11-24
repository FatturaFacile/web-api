<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Rfq\ValueObjects;

use Modules\Procurement\Exceptions\ProcurementRuleViolation;

/**
 * Weighted scoring criteria for evaluating supplier bids. Must sum strictly to 100.
 */
final readonly class EvaluationWeights
{
    public function __construct(
        public int $priceWeight,
        public int $leadTimeWeight,
        public int $supplierRatingWeight,
    ) {
        if ($priceWeight < 0 || $priceWeight > 100 || $leadTimeWeight < 0 || $leadTimeWeight > 100 || $supplierRatingWeight < 0 || $supplierRatingWeight > 100) {
            throw new ProcurementRuleViolation('Each evaluation weight must be between 0 and 100.', 'evaluation_weights');
        }

        if (($priceWeight + $leadTimeWeight + $supplierRatingWeight) !== 100) {
            throw new ProcurementRuleViolation('Evaluation weights (price, lead time, supplier rating) must sum to 100.', 'evaluation_weights');
        }
    }

    public static function default(): self
    {
        return new self(priceWeight: 60, leadTimeWeight: 25, supplierRatingWeight: 15);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        if ($data === null || $data === []) {
            return self::default();
        }

        return new self(
            priceWeight: (int) ($data['price_weight'] ?? 60),
            leadTimeWeight: (int) ($data['lead_time_weight'] ?? 25),
            supplierRatingWeight: (int) ($data['supplier_rating_weight'] ?? 15),
        );
    }

    /**
     * @return array{price_weight: int, lead_time_weight: int, supplier_rating_weight: int}
     */
    public function toArray(): array
    {
        return [
            'price_weight' => $this->priceWeight,
            'lead_time_weight' => $this->leadTimeWeight,
            'supplier_rating_weight' => $this->supplierRatingWeight,
        ];
    }
}
