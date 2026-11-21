<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Queries;

/**
 * Query for loading the supplier-scoped read model of an RFQ portal invitation.
 */
final readonly class GetExternalRfqPortalQuery
{
    public function __construct(
        public string $invitationId,
        public ?string $requestedLocale = null,
    ) {}
}
