<?php

declare(strict_types=1);

namespace Modules\Logistics\Events;

/**
 * Domain hook: raised when a transfer crosses two legal entities of the group. A listener (to be provided by the
 * accounting/invoicing integration) emits the intercompany transfer document or invoice.
 */
final readonly class InterCompanyTransferShipped
{
    public function __construct(
        public string $transferId,
        public string $deliveryNoteId,
        public string $fromCompanyEntityId,
        public string $toCompanyEntityId,
        public string $tenantId,
    ) {}
}
