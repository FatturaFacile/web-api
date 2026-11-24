<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Contracts;

use Carbon\CarbonInterface;
use Modules\ServiceManagement\DTOs\AddWarrantyExtensionDTO;
use Modules\ServiceManagement\DTOs\RegisterEquipmentPassportDTO;
use Modules\ServiceManagement\DTOs\UpdateEquipmentPassportDTO;
use Modules\ServiceManagement\Exceptions\WarrantyRmaException;
use Modules\ServiceManagement\Models\EquipmentComponent;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Warranty\WarrantyResult;

interface EquipmentPassportServiceInterface
{
    public function register(RegisterEquipmentPassportDTO $dto, ?string $actorUserId = null): SerializedEquipmentPassport;

    public function update(SerializedEquipmentPassport $passport, UpdateEquipmentPassportDTO $dto, ?string $actorUserId = null): SerializedEquipmentPassport;

    public function addExtension(SerializedEquipmentPassport $passport, AddWarrantyExtensionDTO $dto, ?string $actorUserId = null): SerializedEquipmentPassport;

    public function evaluateWarranty(SerializedEquipmentPassport $passport): WarrantyResult;

    /**
     * Find the passport of a serial number in the active tenant.
     *
     * @throws WarrantyRmaException 404 serial_not_found / passport_missing
     */
    public function findBySerial(string $serialNumber, ?string $productId = null): SerializedEquipmentPassport;

    /**
     * @return array{passport: SerializedEquipmentPassport, warranty: array<string, mixed>, rma_history: list<array<string, mixed>>, stock_hint: null}
     */
    public function lookup(string $serialNumber, ?string $productId = null, ?CarbonInterface $at = null): array;

    /**
     * @return array{as_built: list<EquipmentComponent>, as_maintained: list<EquipmentComponent>, history: list<array{rma_order_id: string|null, replaced_component_id: string, new_component_id: string|null, at: string|null}>}
     */
    public function genealogy(SerializedEquipmentPassport $passport): array;
}
