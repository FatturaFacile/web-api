<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\BondedWarehouses\Domain\Concerns\ImmutableRecord;
use Modules\BondedWarehouses\Domain\Enums\SdiDocumentType;
use Modules\BondedWarehouses\Domain\Enums\VatDepotMovementType;

/**
 * Row of the telematic load / unload register of an IVA warehouse (Art. 50-bis DL 331/1993), numbered progressively per warehouse.
 *
 * @property string $authorization_id
 * @property int $register_progressive_number
 * @property VatDepotMovementType $movement_type
 * @property Carbon $movement_date
 * @property string $product_id
 * @property string|null $lot_reference
 * @property string $quantity
 * @property string $base_goods_value
 * @property string $storage_and_processing_services_value
 * @property string $total_taxable_extraction_base
 * @property string $vat_rate_percent
 * @property string $vat_amount
 * @property string|null $self_invoice_id
 * @property SdiDocumentType $sdi_document_type
 * @property string|null $f24_payment_reference
 * @property Carbon|null $f24_payment_date
 * @property string|null $notes
 * @property string|null $created_by
 */
final class VatDepotRegisterEntry extends BaseEnterpriseModel
{
    use ImmutableRecord;

    protected $table = 'vat_depot_50bis_registers';

    protected function casts(): array
    {
        return [
            'movement_type' => VatDepotMovementType::class,
            'sdi_document_type' => SdiDocumentType::class,
            'movement_date' => 'date',
            'f24_payment_date' => 'date',
            'register_progressive_number' => 'integer',
            'quantity' => 'decimal:4',
            'base_goods_value' => 'decimal:2',
            'storage_and_processing_services_value' => 'decimal:2',
            'total_taxable_extraction_base' => 'decimal:2',
            'vat_rate_percent' => 'decimal:4',
            'vat_amount' => 'decimal:2',
        ];
    }

    public function isInbound(): bool
    {
        return in_array($this->movement_type, [VatDepotMovementType::InboundFreeCirculation, VatDepotMovementType::InboundIntraEU], true);
    }

    public function isExtraction(): bool
    {
        return in_array($this->movement_type, [VatDepotMovementType::ExtractionTD22, VatDepotMovementType::ExtractionTD23, VatDepotMovementType::ExtractionLetterOfIntent], true);
    }
}
