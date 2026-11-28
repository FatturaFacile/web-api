<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Application\Commands;

use Modules\BondedWarehouses\Domain\Enums\DeclarationType;

/**
 * Entry of goods under an MRN into a customs warehouse. The suspended duty and VAT are calculated from the value and the rates when not stated.
 */
final readonly class RegisterBondedWarehouseInboundMrnCommand
{
    public function __construct(
        public string $authorizationId,
        public string $mrnNumber,
        public int $mrnItemNumber,
        public DeclarationType $declarationType,
        public string $entryDate,
        public string $productId,
        public ?string $wmsLotId,
        public string $taricHsCode,
        public string $countryOfOrigin,
        public bool $preferentialOrigin,
        public ?string $preferentialAgreementCode,
        public float $quantity,
        public string $unitOfMeasure,
        public float $netWeightKg,
        public float $grossWeightKg,
        public float $customsStatisticalValue,
        public float $dutyRatePercent,
        public float $vatRatePercent,
        public ?float $suspendedDutyAmount,
        public ?float $suspendedVatAmount,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            authorizationId: (string) $data['authorization_id'],
            mrnNumber: (string) $data['mrn_number'],
            mrnItemNumber: (int) ($data['mrn_item_number'] ?? 1),
            declarationType: DeclarationType::from((string) $data['declaration_type']),
            entryDate: (string) $data['entry_date'],
            productId: (string) $data['product_id'],
            wmsLotId: isset($data['wms_lot_id']) ? (string) $data['wms_lot_id'] : null,
            taricHsCode: (string) $data['taric_hs_code'],
            countryOfOrigin: strtoupper((string) $data['country_of_origin']),
            preferentialOrigin: (bool) ($data['preferential_origin'] ?? false),
            preferentialAgreementCode: isset($data['preferential_agreement_code']) ? (string) $data['preferential_agreement_code'] : null,
            quantity: (float) $data['initial_quantity'],
            unitOfMeasure: (string) ($data['unit_of_measure'] ?? 'EA'),
            netWeightKg: (float) ($data['initial_net_weight_kg'] ?? 0),
            grossWeightKg: (float) ($data['initial_gross_weight_kg'] ?? ($data['initial_net_weight_kg'] ?? 0)),
            customsStatisticalValue: (float) $data['customs_statistical_value'],
            dutyRatePercent: (float) ($data['duty_rate_percent'] ?? 0),
            vatRatePercent: (float) ($data['vat_rate_percent'] ?? 0),
            suspendedDutyAmount: isset($data['suspended_duty_amount']) ? (float) $data['suspended_duty_amount'] : null,
            suspendedVatAmount: isset($data['suspended_vat_amount']) ? (float) $data['suspended_vat_amount'] : null,
        );
    }
}
