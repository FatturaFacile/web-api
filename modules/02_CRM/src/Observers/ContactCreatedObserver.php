<?php

declare(strict_types=1);

namespace Modules\CRM\Observers;

use App\Models\Contact;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\CRM\Events\ContactCreatedEvent;
use Throwable;

/**
 * Raises ContactCreatedEvent when a Contact row is inserted. Every creation path (the contact API, the purchase and
 * e-commerce imports, the conversion of a lead, the party master, the associations registry) goes through the model, so
 * one hook covers them all and none of them raises the event itself: no double raising. Contacts without a tenant
 * (generic records) raise nothing. It never breaks the insert: a failure is logged and rolled back to a savepoint.
 */
final class ContactCreatedObserver
{
    public function __construct(private readonly EventDispatcher $dispatcher) {}

    public function created(Contact $contact): void
    {
        $tenantId = (string) $contact->tenant_id;

        if (! Str::isUuid($tenantId)) {
            return;
        }

        try {
            DB::transaction(fn () => $this->dispatcher->publishAndDispatch(new ContactCreatedEvent(
                contactId: (string) $contact->id,
                contactType: (string) $contact->type,
                companyName: $contact->company_name,
                firstName: $contact->first_name,
                lastName: $contact->last_name,
                email: $contact->email,
                country: $contact->country,
                vatNumber: $contact->vat_number,
                tenantId: $tenantId,
                companyId: $tenantId,
            )));
        } catch (Throwable $exception) {
            Log::error('The contact created event could not be raised.', ['contact_id' => $contact->id, 'exception' => $exception->getMessage()]);
        }
    }
}
