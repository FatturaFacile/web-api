<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Application\Commands;

use Modules\BondedWarehouses\Domain\Enums\AllocationMethod;
use Modules\BondedWarehouses\Domain\Enums\DischargeType;

/**
 * Discharge of bonded stock: FIFO over the oldest MRN lots of a product, or Specific_MRN over the lots named. With $simulate the plan
 * is calculated and nothing is written.
 */
final readonly class ExecuteBondedStockDischargeCommand
{
    /**
     * @param  list<array{mrn_lot_id: string, quantity: float, net_weight_kg?: float|null}>  $allocations  Specific_MRN only
     */
    public function __construct(
        public string $authorizationId,
        public DischargeType $dischargeType,
        public AllocationMethod $allocationMethod,
        public string $dischargeDate,
        public ?string $productId,
        public ?float $quantity,
        public array $allocations,
        public ?string $dischargeMrnNumber,
        public ?string $usualHandlingCode,
        public ?string $usualHandlingNotes,
        public ?string $customsProspectusDocumentId,
        public ?string $userId,
        public bool $simulate = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public static function fromArray(array $data, ?string $userId, bool $simulate = false): self
    {
        /** @var list<array{mrn_lot_id: string, quantity: float, net_weight_kg?: float|null}> $allocations */
        $allocations = array_map(static fn (array $row): array => [
            'mrn_lot_id' => (string) $row['mrn_lot_id'],
            'quantity' => (float) $row['quantity'],
            'net_weight_kg' => isset($row['net_weight_kg']) ? (float) $row['net_weight_kg'] : null,
        ], array_values((array) ($data['allocations'] ?? [])));

        return new self(
            authorizationId: (string) $data['authorization_id'],
            dischargeType: DischargeType::from((string) $data['discharge_type']),
            allocationMethod: AllocationMethod::from((string) $data['allocation_method']),
            dischargeDate: (string) $data['discharge_date'],
            productId: isset($data['product_id']) ? (string) $data['product_id'] : null,
            quantity: isset($data['quantity']) ? (float) $data['quantity'] : null,
            allocations: $allocations,
            dischargeMrnNumber: isset($data['discharge_mrn_number']) ? (string) $data['discharge_mrn_number'] : null,
            usualHandlingCode: isset($data['usual_handling_code']) ? (string) $data['usual_handling_code'] : null,
            usualHandlingNotes: isset($data['usual_handling_notes']) ? (string) $data['usual_handling_notes'] : null,
            customsProspectusDocumentId: isset($data['customs_prospectus_document_id']) ? (string) $data['customs_prospectus_document_id'] : null,
            userId: $userId,
            simulate: $simulate,
        );
    }
}
