<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\BondedWarehouses\Application\Commands\RegisterVatDepot50BisInboundCommand;
use Modules\BondedWarehouses\Application\Services\VatDepotRegisterService;
use Modules\BondedWarehouses\Domain\Enums\SdiDocumentType;
use Modules\BondedWarehouses\Domain\Enums\VatDepotMovementType;
use Modules\BondedWarehouses\Domain\Exceptions\BondedWarehouseException;
use Modules\BondedWarehouses\Models\VatDepotRegisterEntry;

/**
 * Adds a load row to the Art. 50-bis register: goods introduced in free circulation or from another EU State, or transferred from another IVA
 * warehouse without VAT. No VAT is due on any of them.
 */
final class RegisterVatDepot50BisInboundHandler
{
    public function __construct(private readonly VatDepotRegisterService $register) {}

    /**
     * @throws BondedWarehouseException movement_not_inbound, quantity_must_be_positive, authorization_not_found, authorization_inactive, regime_not_vat_depot
     */
    public function handle(RegisterVatDepot50BisInboundCommand $command): VatDepotRegisterEntry
    {
        if (! in_array($command->movementType, [VatDepotMovementType::InboundFreeCirculation, VatDepotMovementType::InboundIntraEU, VatDepotMovementType::InternalTransferNoVAT], true)) {
            throw BondedWarehouseException::because('movement_not_inbound');
        }

        if ($command->quantity <= 0.0) {
            throw BondedWarehouseException::because('quantity_must_be_positive');
        }

        return DB::transaction(function () use ($command): VatDepotRegisterEntry {
            $authorization = $this->register->lockDepot($command->authorizationId, $command->movementDate);

            return VatDepotRegisterEntry::query()->create([
                'tenant_id' => $authorization->tenant_id,
                'authorization_id' => $authorization->id,
                'register_progressive_number' => $this->register->nextProgressive((string) $authorization->id),
                'movement_type' => $command->movementType,
                'movement_date' => $command->movementDate,
                'product_id' => $command->productId,
                'lot_reference' => $command->lotReference,
                'quantity' => $command->quantity,
                'base_goods_value' => $command->baseGoodsValue,
                'storage_and_processing_services_value' => 0,
                'total_taxable_extraction_base' => 0,
                'vat_rate_percent' => 0,
                'vat_amount' => 0,
                'sdi_document_type' => SdiDocumentType::None,
                'notes' => $command->notes,
                'created_by' => $command->userId,
            ])->refresh();
        });
    }
}
