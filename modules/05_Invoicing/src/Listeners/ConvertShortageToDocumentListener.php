<?php

declare(strict_types=1);

namespace Modules\Invoicing\Listeners;

use Modules\Invoicing\Actions\ConvertReplenishmentRequestAction;
use Modules\Invoicing\Events\ShortageDetectedForProcurementEvent;
use Modules\Invoicing\Events\ShortageDetectedForProductionEvent;

/**
 * A shortage detected on a sales order becomes a purchase requisition (procurement event) or a production order
 * (production event) for the replenishment request the event names, once and in the tenant of the request.
 */
final readonly class ConvertShortageToDocumentListener
{
    public function __construct(private ConvertReplenishmentRequestAction $convert) {}

    public function handle(ShortageDetectedForProcurementEvent|ShortageDetectedForProductionEvent $event): void
    {
        $requestId = $event->details['replenishment_request_id'] ?? null;

        if (! is_string($requestId) || $event->tenantId === '') {
            return;
        }

        $this->convert->execute($requestId, $event->tenantId);
    }
}
