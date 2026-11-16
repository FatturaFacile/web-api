<?php

declare(strict_types=1);

namespace Modules\Logistics\Services;

use Modules\Inventory\Contracts\TransferRouteValidator;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\Warehouse;
use Modules\Logistics\Enums\LegalOwnerType;
use Modules\Logistics\Enums\TransferPurpose;
use Modules\Logistics\Enums\WarehouseType;
use Modules\Logistics\Support\LogisticsMessage;

/**
 * Which warehouses a transfer purpose may connect: consignment goes to a customer deposit and comes back from it,
 * subcontracting goes to and returns from a subcontractor, returns and inter-company moves land on our own premises,
 * inter-company moves cross two legal entities and name the partner to invoice.
 */
final class TransferRoutePolicy implements TransferRouteValidator
{
    public function assertValid(string $purpose, Warehouse $source, Warehouse $destination): void
    {
        $purpose = TransferPurpose::from($purpose);

        if ($source->warehouse_type === WarehouseType::Transit->value || $source->legal_owner_type !== LegalOwnerType::Company->value) {
            throw new StockRuleViolation(LogisticsMessage::get('LOGISTICS.ERRORS.CANNOT_SHIP_FROM_TRANSIT'));
        }

        $originMustBe = match ($purpose) {
            TransferPurpose::ConsignmentReturn => WarehouseType::CustomerConsignment,
            TransferPurpose::SubcontractingReturn => WarehouseType::Subcontractor,
            default => null,
        };
        if ($originMustBe !== null && $source->warehouse_type !== $originMustBe->value) {
            throw new StockRuleViolation(LogisticsMessage::get('LOGISTICS.ERRORS.ORIGIN_TYPE_FOR_PURPOSE', ['type' => $originMustBe->value]));
        }

        $destinationMustBe = match ($purpose) {
            TransferPurpose::CustomerConsignment => WarehouseType::CustomerConsignment,
            TransferPurpose::Subcontracting => WarehouseType::Subcontractor,
            TransferPurpose::TechnicianVan => WarehouseType::MobileVan,
            default => null,
        };
        if ($destinationMustBe !== null && $destination->warehouse_type !== $destinationMustBe->value) {
            throw new StockRuleViolation(LogisticsMessage::get('LOGISTICS.ERRORS.DEST_TYPE_FOR_PURPOSE', ['type' => $destinationMustBe->value]));
        }

        $ownPremises = [WarehouseType::Standard->value, WarehouseType::MobileVan->value];
        $needsOwnPremises = in_array($purpose, [TransferPurpose::ConsignmentReturn, TransferPurpose::SubcontractingReturn, TransferPurpose::InterCompany], true);
        if ($needsOwnPremises && ! in_array($destination->warehouse_type, $ownPremises, true)) {
            throw new StockRuleViolation(LogisticsMessage::get('LOGISTICS.ERRORS.DEST_TYPE_INVALID'));
        }
        if ($purpose === TransferPurpose::InterWarehouse
            && in_array($destination->warehouse_type, [WarehouseType::Transit->value, WarehouseType::VendorConsignment->value], true)) {
            throw new StockRuleViolation(LogisticsMessage::get('LOGISTICS.ERRORS.DEST_TYPE_INVALID'));
        }

        if ($purpose === TransferPurpose::InterCompany) {
            if ($source->company_entity_id === null || $destination->company_entity_id === null || $source->company_entity_id === $destination->company_entity_id) {
                throw new StockRuleViolation(LogisticsMessage::get('LOGISTICS.ERRORS.INTER_COMPANY_ENTITIES'));
            }
            if ($destination->linked_partner_id === null) {
                throw new StockRuleViolation(LogisticsMessage::get('LOGISTICS.ERRORS.INTER_COMPANY_PARTNER'));
            }
        }
    }
}
