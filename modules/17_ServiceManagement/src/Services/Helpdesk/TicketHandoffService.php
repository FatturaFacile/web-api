<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use Illuminate\Support\Facades\DB;
use Modules\ServiceManagement\DTOs\CreateWorkOrderDTO;
use Modules\ServiceManagement\DTOs\IssueRmaDTO;
use Modules\ServiceManagement\Exceptions\HelpdeskRuleViolation;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketMessage;
use Modules\ServiceManagement\Models\WorkOrder;
use Modules\ServiceManagement\Services\RmaService;
use Modules\ServiceManagement\Services\WorkOrderAndFieldService;

/**
 * One-click handoff of a ticket to the field (work order, Mod 084) or to the depot (RMA, Mod 089).
 *
 * The new record points back to the ticket (ticket_id), so the whole conversation stays one click away, and starts with
 * a transcript of the thread. A ticket hands off to the same kind of record once: a second call returns the first,
 * which makes a double click or a retry harmless.
 */
final readonly class TicketHandoffService
{
    private const int TranscriptLimit = 8000;

    public function __construct(
        private WorkOrderAndFieldService $workOrders,
        private RmaService $rmas,
        private TicketActivityLog $activityLog,
        private TicketMessageWriter $messageWriter,
    ) {}

    /**
     * @param  array{work_type?: string, scheduled_start?: string|null, scheduled_end?: string|null, location_address?: string|null}  $options
     */
    public function toFieldService(Ticket $ticket, array $options = [], ?string $actorUserId = null): WorkOrder
    {
        return DB::transaction(function () use ($ticket, $options, $actorUserId): WorkOrder {
            $ticket = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            $existing = WorkOrder::query()->where('tenant_id', $ticket->tenant_id)->where('ticket_id', $ticket->id)->orderBy('created_at')->first();

            if ($existing !== null) {
                return $existing;
            }

            $this->assertHandoffAllowed($ticket);

            $workOrder = $this->workOrders->createWorkOrder(new CreateWorkOrderDTO(
                tenantId: (string) $ticket->tenant_id,
                companyId: (string) ($ticket->company_id ?? $ticket->tenant_id),
                title: mb_substr((string) $ticket->subject, 0, 200),
                description: $this->transcript($ticket),
                workType: $options['work_type'] ?? 'corrective',
                priority: $this->workOrderPriority((string) $ticket->priority),
                serviceCategoryId: $ticket->category_id,
                customerId: (string) $ticket->customer_id,
                serviceContractId: $ticket->service_contract_id,
                assetId: $ticket->asset_id,
                ticketId: $ticket->id,
                projectId: $ticket->project_id,
                serviceTeamId: $ticket->assigned_team_id,
                leadTechnicianId: $ticket->assigned_technician_id,
                scheduledStart: $options['scheduled_start'] ?? null,
                scheduledEnd: $options['scheduled_end'] ?? null,
                locationAddress: $options['location_address'] ?? null,
                billingType: $ticket->service_contract_id === null ? 'time_and_materials' : 'contract_included',
            ), $actorUserId);

            $passportId = $this->passportOf($ticket)?->id;

            if ($passportId !== null) {
                $workOrder->equipment_passport_id = $passportId;
                $workOrder->save();
            }

            $this->recordHandoff($ticket, 'field_service', $workOrder->id, (string) $workOrder->work_order_number, $actorUserId);

            return $workOrder;
        });
    }

    /**
     * @param  array{serial_number?: string|null, equipment_passport_id?: string|null, goodwill?: bool, reported_symptom?: string|null}  $options
     * @return array{rma: DepotRepairRmaOrder, tracking_token: string|null, created: bool}
     */
    public function toRma(Ticket $ticket, array $options = [], ?string $actorUserId = null): array
    {
        return DB::transaction(function () use ($ticket, $options, $actorUserId): array {
            $ticket = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            $existing = DepotRepairRmaOrder::query()->where('tenant_id', $ticket->tenant_id)->where('ticket_id', $ticket->id)->orderBy('created_at')->first();

            if ($existing !== null) {
                return ['rma' => $existing, 'tracking_token' => null, 'created' => false];
            }

            $this->assertHandoffAllowed($ticket);

            $passport = $this->passportOf($ticket, $options['equipment_passport_id'] ?? null, $options['serial_number'] ?? null)
                ?? throw new HelpdeskRuleViolation('An RMA needs the serial number of the unit: state it or link the equipment passport to the ticket.');

            $issued = $this->rmas->issue($passport, new IssueRmaDTO(
                tenantId: (string) $ticket->tenant_id,
                companyId: $ticket->company_id,
                reportedSymptom: $options['reported_symptom'] ?? $this->transcript($ticket),
                serialNumber: $passport->serial_number,
                equipmentPassportId: $passport->id,
                goodwill: (bool) ($options['goodwill'] ?? false),
            ), $actorUserId);

            /** @var DepotRepairRmaOrder $rma */
            $rma = $issued['rma'];
            $rma->ticket_id = $ticket->id;
            $rma->save();

            $this->recordHandoff($ticket, 'rma', $rma->id, (string) $rma->rma_number, $actorUserId);

            return ['rma' => $rma, 'tracking_token' => $issued['tracking_token'], 'created' => true];
        });
    }

    /**
     * The conversation as text: description, then every message with its author and whether it was public or an
     * internal note. Cut at a fixed size, keeping the most recent messages.
     */
    public function transcript(Ticket $ticket): string
    {
        $lines = [];

        foreach (TicketMessage::query()->where('ticket_id', $ticket->id)->orderBy('created_at')->orderBy('id')->get() as $message) {
            $kind = $message->visibility === 'internal' ? 'INTERNAL NOTE' : 'PUBLIC';
            $lines[] = sprintf('[%s] %s (%s): %s', $message->created_at?->format('Y-m-d H:i'), $message->author_name ?? $message->author_type, $kind, trim((string) $message->body));
        }

        $text = (string) $ticket->description;

        while ($lines !== [] && mb_strlen($text."\n\n".implode("\n", $lines)) > self::TranscriptLimit) {
            array_shift($lines);
        }

        return $lines === [] ? $text : $text."\n\n".implode("\n", $lines);
    }

    private function assertHandoffAllowed(Ticket $ticket): void
    {
        if (! $ticket->statusEnum()->isActive()) {
            throw new HelpdeskRuleViolation('A resolved or closed ticket cannot be handed off: reopen it first.');
        }

        if ($ticket->merged_into_ticket_id !== null) {
            throw new HelpdeskRuleViolation('A merged ticket cannot be handed off: use the ticket it was merged into.');
        }
    }

    private function passportOf(Ticket $ticket, ?string $passportId = null, ?string $serialNumber = null): ?SerializedEquipmentPassport
    {
        $metadata = is_array($ticket->metadata) ? $ticket->metadata : [];
        $passportId ??= is_string($metadata['equipment_passport_id'] ?? null) ? $metadata['equipment_passport_id'] : null;
        $query = SerializedEquipmentPassport::query()->where('tenant_id', $ticket->tenant_id);

        if ($passportId !== null) {
            return $query->whereKey($passportId)->first();
        }

        return $serialNumber === null || $serialNumber === '' ? null : $query->where('serial_number', $serialNumber)->first();
    }

    private function workOrderPriority(string $ticketPriority): string
    {
        return in_array($ticketPriority, ['low', 'medium', 'high', 'critical'], true) ? $ticketPriority : 'medium';
    }

    private function recordHandoff(Ticket $ticket, string $kind, string $recordId, string $reference, ?string $actorUserId): void
    {
        $metadata = is_array($ticket->metadata) ? $ticket->metadata : [];
        $metadata['handoffs'][] = ['kind' => $kind, 'id' => $recordId, 'reference' => $reference, 'at' => now()->toIso8601String()];
        $ticket->metadata = $metadata;
        $ticket->save();

        $this->activityLog->record($ticket, 'handed_off', null, $kind, ['record_id' => $recordId, 'reference' => $reference], $actorUserId);
        $this->messageWriter->systemNote($ticket, "Handed off to {$kind}: {$reference}", $actorUserId);
    }
}
