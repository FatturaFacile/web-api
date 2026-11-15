<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Actions;

use Modules\ServiceManagement\Contracts\EquipmentPassportServiceInterface;
use Modules\ServiceManagement\Contracts\RmaServiceInterface;
use Modules\ServiceManagement\DTOs\IssueRmaDTO;
use Modules\ServiceManagement\Exceptions\WarrantyRmaException;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;

/**
 * Command "LookupSerialWarrantyAndIssueRma": resolve the equipment passport (by id or serial), evaluate the
 * warranty and issue an RMA in `awaiting_arrival` with its shipping label and one-time tracking token.
 */
final readonly class LookupSerialWarrantyAndIssueRmaAction
{
    public function __construct(
        private EquipmentPassportServiceInterface $passportService,
        private RmaServiceInterface $rmaService,
    ) {}

    /**
     * @return array{rma: DepotRepairRmaOrder, label: array<string, mixed>}
     */
    public function execute(IssueRmaDTO $dto, ?string $actorUserId = null): array
    {
        $passport = $this->resolvePassport($dto);

        $issued = $this->rmaService->issue($passport, $dto, $actorUserId);

        return [
            'rma' => $issued['rma'],
            'label' => $this->rmaService->label($issued['rma'], $issued['tracking_token']),
        ];
    }

    private function resolvePassport(IssueRmaDTO $dto): SerializedEquipmentPassport
    {
        if ($dto->equipmentPassportId !== null) {
            $passport = SerializedEquipmentPassport::query()->whereKey($dto->equipmentPassportId)->first();
            if ($passport === null) {
                throw WarrantyRmaException::notFound('passport_missing', 'Equipment passport not found.');
            }

            return $passport;
        }

        return $this->passportService->findBySerial((string) $dto->serialNumber, $dto->productId);
    }
}
