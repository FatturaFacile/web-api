<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Queries;

/**
 * Query for loading a full RFQ detail aggregate for an authenticated buyer.
 */
final readonly class GetRfqDetailQuery
{
    public function __construct(
        public string $rfqId,
    ) {}
}
