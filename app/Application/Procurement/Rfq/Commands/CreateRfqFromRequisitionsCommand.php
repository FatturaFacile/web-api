<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Commands;

/**
 * Command to create a draft RFQ by importing & optionally consolidating approved Mod 044 PR lines.
 */
final readonly class CreateRfqFromRequisitionsCommand
{
    /**
     * @param  list<string>  $purchaseRequisitionIds
     * @param  list<string>|null  $lineIds
     * @param  list<string>  $supplierIds
     * @param  array{price_weight?: int, lead_time_weight?: int, supplier_rating_weight?: int}|null  $evaluationWeights
     */
    public function __construct(
        public string $tenantId,
        public ?string $actorUserId,
        public array $purchaseRequisitionIds,
        public ?array $lineIds,
        public string $title,
        public ?string $description,
        public ?string $currencyCode,
        public ?string $incoterms,
        public string $submissionDeadline,
        public ?string $expectedDeliveryDate,
        public ?array $evaluationWeights,
        public bool $sealedBidding,
        public bool $consolidateMatchingItems,
        public array $supplierIds,
        public ?string $notes = null,
    ) {}
}
