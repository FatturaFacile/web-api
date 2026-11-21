<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Commands;

use App\Domain\Procurement\Rfq\Enums\AwardMode;

/**
 * Command to execute Full Award or Split Award on an RFQ, generating Mod 046 Purchase Orders.
 */
final readonly class AwardRfqCommand
{
    /**
     * @param  list<array{rfq_line_id: string, supplier_bid_line_id: string}>  $lineAwards
     */
    public function __construct(
        public string $rfqId,
        public ?string $actorUserId,
        public AwardMode $awardMode,
        public ?string $supplierId = null,
        public ?string $bidId = null,
        public array $lineAwards = [],
        public ?string $expectedDeliveryDate = null,
        public ?string $notes = null,
    ) {}
}
