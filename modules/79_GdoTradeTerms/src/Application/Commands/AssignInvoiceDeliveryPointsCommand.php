<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

final readonly class AssignInvoiceDeliveryPointsCommand
{
    /**
     * @param  array<int|string, mixed>  $assignments
     */
    public function __construct(
        public array $assignments,
        public string $tenantId,
        public ?string $userId,
    ) {}
}
