<?php

declare(strict_types=1);

namespace Modules\Inventory\Listeners;

use Modules\Inventory\Services\SafetyDataSheetService;
use Modules\Invoicing\Events\DeliveryNoteIssuedEvent;

/**
 * The sheets leave with the delivery document, when the delivery note is issued.
 */
final readonly class SendSafetyDataSheetsOnDeliveryListener
{
    public function __construct(
        private SafetyDataSheetService $sheets
    ) {}

    public function handle(DeliveryNoteIssuedEvent $event): void
    {
        $this->sheets->sendForDelivery($event->deliveryNoteId);
    }
}
