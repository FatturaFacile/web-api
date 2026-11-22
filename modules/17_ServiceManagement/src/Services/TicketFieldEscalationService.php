<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services;

use App\Models\Contact;
use App\Models\ContactAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\ServiceManagement\Contracts\WorkOrderAndFieldServiceInterface;
use Modules\ServiceManagement\DTOs\CreateWorkOrderDTO;
use Modules\ServiceManagement\DTOs\EscalateTicketDTO;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\WorkOrder;

/**
 * Ticket -> field intervention hand-off: an on-site ticket becomes a work order that inherits the customer, the
 * installed asset, the covering maintenance contract, the SLA deadline and the diagnostic notes, and the ticket is
 * escalated. The hand-off is idempotent: a ticket with an open work order returns that work order.
 */
final readonly class TicketFieldEscalationService
{
    public function __construct(
        private WorkOrderAndFieldServiceInterface $workOrders,
        private TicketServiceInterface $tickets,
    ) {}

    /**
     * @return array{work_order: WorkOrder, created: bool}
     *
     * @throws ValidationException when the ticket is already resolved, closed or cancelled
     */
    public function escalate(Ticket $ticket, ?string $diagnosticNotes = null, ?string $scheduledStart = null, ?string $technicianId = null, ?string $actorUserId = null): array
    {
        return DB::transaction(function () use ($ticket, $diagnosticNotes, $scheduledStart, $technicianId, $actorUserId): array {
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['resolved', 'closed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['ticket' => __('customer_360.ticket_not_escalatable')]);
            }

            $existing = WorkOrder::query()
                ->where('ticket_id', $locked->id)
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->latest('created_at')
                ->first();

            if ($existing !== null) {
                return ['work_order' => $existing, 'created' => false];
            }

            $description = trim($locked->subject."\n\n".$locked->description.($diagnosticNotes !== null && $diagnosticNotes !== '' ? "\n\n".$diagnosticNotes : ''));

            $workOrder = $this->workOrders->createWorkOrder(new CreateWorkOrderDTO(
                tenantId: (string) $locked->tenant_id,
                companyId: (string) ($locked->company_id ?? $locked->tenant_id),
                title: $locked->subject,
                description: $description,
                workType: 'corrective',
                priority: (string) $locked->priority,
                customerId: (string) $locked->customer_id,
                serviceContractId: $locked->service_contract_id,
                assetId: $locked->asset_id,
                ticketId: (string) $locked->id,
                projectId: $locked->project_id,
                leadTechnicianId: $technicianId ?? $locked->assigned_technician_id,
                scheduledStart: $scheduledStart,
                scheduledEnd: $locked->resolution_due_at?->toDateTimeString(),
                locationAddress: $this->siteAddress((string) $locked->customer_id),
            ), $actorUserId);

            $this->tickets->escalateTicket(new EscalateTicketDTO(
                ticketId: (string) $locked->id,
                reason: 'on_site_required',
                escalationLevel: max(1, (int) $locked->escalation_level),
                escalatedBy: $actorUserId,
            ), $actorUserId);

            return ['work_order' => $workOrder, 'created' => true];
        });
    }

    /**
     * The service address of the customer: the default site address, else any site, else the default address.
     */
    private function siteAddress(string $contactId): ?string
    {
        $contact = Contact::query()->find($contactId);
        if ($contact === null) {
            return null;
        }

        $addresses = ContactAddress::query()->where('contact_id', $contactId)->get();
        $address = $addresses->first(fn (ContactAddress $a): bool => $a->type === 'site' && $a->is_default)
            ?? $addresses->first(fn (ContactAddress $a): bool => $a->type === 'site')
            ?? $addresses->first(fn (ContactAddress $a): bool => $a->is_default)
            ?? $addresses->first();

        if ($address === null) {
            return null;
        }

        return trim(implode(' ', array_filter([
            trim($address->street.' '.$address->house_number),
            trim($address->postal_code.' '.$address->city),
            $address->country,
        ], static fn (?string $part): bool => $part !== null && $part !== '')));
    }
}
