<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Application\Commands;

use Modules\BondedWarehouses\Domain\Enums\VatDepotMovementType;

/**
 * Extraction of goods from an IVA warehouse. The movement type picks the self-invoice: Extraction_TD22 (VAT in the periodic settlement),
 * Extraction_TD23 (VAT paid with a non-compensable F24, reference and date required) or Extraction_LetterOfIntent (TD22 without VAT).
 */
final readonly class ExecuteVatDepotExtractionAndEmitSelfInvoiceCommand
{
    public function __construct(
        public string $authorizationId,
        public VatDepotMovementType $movementType,
        public string $movementDate,
        public string $productId,
        public ?string $lotReference,
        public float $quantity,
        public float $baseGoodsValue,
        public float $storageAndProcessingServicesValue,
        public float $vatRatePercent,
        public ?string $f24PaymentReference,
        public ?string $f24PaymentDate,
        public ?string $declarationOfIntentId,
        public ?string $selfContactId,
        public ?string $notes,
        public ?string $userId,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public static function fromArray(array $data, ?string $userId): self
    {
        $type = VatDepotMovementType::from((string) $data['movement_type']);

        return new self(
            authorizationId: (string) $data['authorization_id'],
            movementType: $type,
            movementDate: (string) $data['movement_date'],
            productId: (string) $data['product_id'],
            lotReference: isset($data['lot_reference']) ? (string) $data['lot_reference'] : null,
            quantity: (float) $data['quantity'],
            baseGoodsValue: (float) $data['base_goods_value'],
            storageAndProcessingServicesValue: (float) ($data['storage_and_processing_services_value'] ?? 0),
            vatRatePercent: $type === VatDepotMovementType::ExtractionLetterOfIntent ? 0.0 : (float) ($data['vat_rate_percent'] ?? 22),
            f24PaymentReference: isset($data['f24_payment_reference']) ? (string) $data['f24_payment_reference'] : null,
            f24PaymentDate: isset($data['f24_payment_date']) ? (string) $data['f24_payment_date'] : null,
            declarationOfIntentId: isset($data['declaration_of_intent_id']) ? (string) $data['declaration_of_intent_id'] : null,
            selfContactId: isset($data['self_contact_id']) ? (string) $data['self_contact_id'] : null,
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
            userId: $userId,
        );
    }
}
