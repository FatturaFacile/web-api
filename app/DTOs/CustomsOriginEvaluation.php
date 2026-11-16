<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\FreeTradeAgreement;

/**
 * Immutable outcome of the customs origin evaluation of an invoice.
 */
final readonly class CustomsOriginEvaluation
{
    public function __construct(
        public string $exporterCountry,
        public string $importerCountry,
        public ?FreeTradeAgreement $agreement,
        public float $preferentialValue,
        public ?float $threshold,
        public string $thresholdCurrency,
        public bool $aboveThreshold,
        public ?string $authorizationNumber,
        public ?string $authorizationType,
        public bool $declarationAllowed,
        public bool $dutyExemptionEligible,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'exporter_country' => $this->exporterCountry,
            'importer_country' => $this->importerCountry,
            'free_trade_agreement_id' => $this->agreement?->id,
            'free_trade_agreement_code' => $this->agreement?->code,
            'preferential_value' => $this->preferentialValue,
            'threshold' => $this->threshold,
            'threshold_currency' => $this->thresholdCurrency,
            'above_threshold' => $this->aboveThreshold,
            'authorization_number' => $this->authorizationNumber,
            'authorization_type' => $this->authorizationType,
            'declaration_allowed' => $this->declarationAllowed,
            'duty_exemption_eligible' => $this->dutyExemptionEligible,
        ];
    }
}
