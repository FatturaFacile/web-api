<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Domain\Support;

use Modules\BondedWarehouses\Domain\Enums\SdiDocumentType;
use Modules\BondedWarehouses\Domain\Enums\VatDepotMovementType;
use Modules\BondedWarehouses\Domain\Exceptions\BondedWarehouseException;

/**
 * Taxable base and VAT of the extraction of goods from an IVA warehouse (Art. 50-bis, para. 4, DL 331/1993): the value of the goods plus
 * the value of the storage and processing services rendered in the warehouse.
 */
final class VatDepotCalculator
{
    public const string LETTER_OF_INTENT_NATURE = 'N3.5';

    /**
     * @return array{base: float, vat_amount: float, total: float}
     */
    public static function extraction(float $goodsValue, float $servicesValue, float $vatRatePercent): array
    {
        $base = round($goodsValue + $servicesValue, 2);
        $vat = round($base * $vatRatePercent / 100, 2);

        return ['base' => $base, 'vat_amount' => $vat, 'total' => round($base + $vat, 2)];
    }

    /**
     * TD22: the extractor accounts for the VAT in the periodic settlement (or, with the letter of intent, does not owe it); TD23: the VAT is paid with a
     * non-compensable F24 before the extraction.
     */
    public static function documentTypeOf(VatDepotMovementType $type): SdiDocumentType
    {
        return match ($type) {
            VatDepotMovementType::ExtractionTD23 => SdiDocumentType::TD23,
            VatDepotMovementType::ExtractionTD22, VatDepotMovementType::ExtractionLetterOfIntent => SdiDocumentType::TD22,
            default => SdiDocumentType::None,
        };
    }

    /**
     * @throws BondedWarehouseException f24_required, f24_not_allowed
     */
    public static function assertPaymentMode(VatDepotMovementType $type, ?string $f24Reference, mixed $f24Date): void
    {
        if ($type === VatDepotMovementType::ExtractionTD23 && ($f24Reference === null || $f24Reference === '' || $f24Date === null || $f24Date === '')) {
            throw BondedWarehouseException::because('f24_required');
        }

        if ($type !== VatDepotMovementType::ExtractionTD23 && ($f24Reference !== null && $f24Reference !== '')) {
            throw BondedWarehouseException::because('f24_not_allowed');
        }
    }
}
