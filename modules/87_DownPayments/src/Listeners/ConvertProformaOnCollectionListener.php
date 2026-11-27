<?php

declare(strict_types=1);

namespace Modules\DownPayments\Listeners;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Modules\DownPayments\Services\AdvanceEmissionService;
use Modules\Treasury\Events\CollectionReceivedEvent;
use Throwable;

/**
 * A collection confirmed by Treasury (bank reconciliation of camt.054 or CBI movements) against a pro-forma advance request turns
 * the request into the official advance invoice (TD02) dated on the payment day and settles it with the collection.
 *
 * A collection that names any other document is none of the chain's business and passes untouched. The receipt is identified by its
 * event id, so the same receipt delivered twice converts once.
 */
final readonly class ConvertProformaOnCollectionListener
{
    public function __construct(private AdvanceEmissionService $advances) {}

    public function handle(CollectionReceivedEvent $event): void
    {
        if ($event->invoiceId === null) {
            return;
        }

        try {
            $tenant = Tenant::query()->find($event->tenantId);

            if ($tenant === null) {
                return;
            }

            // The tables of the chain are tenant-scoped: the conversion runs inside the company of the collection
            app(TenantContext::class)->runWithTenant($tenant, fn () => $this->advances->convertCollected(
                $event->tenantId,
                $event->invoiceId,
                $event->collectedAmount,
                $event->collectionDate,
                'COLL-'.$event->collectionId.'/'.($event->eventId !== '' ? $event->eventId : $event->collectionDate),
            ));
        } catch (Throwable $e) {
            // The collection itself is already booked by Treasury: a failed conversion is reported and can be retried by hand
            Log::error('Pro-forma advance request not converted after a collection.', ['proforma_id' => $event->invoiceId, 'collection_id' => $event->collectionId, 'error' => $e->getMessage()]);
        }
    }
}
