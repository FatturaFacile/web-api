<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\ServiceManagement\Contracts\IncidentManagementServiceInterface;
use Modules\ServiceManagement\DTOs\CreateIncidentDTO;
use Modules\ServiceManagement\Events\IncidentCreatedEvent;
use Modules\ServiceManagement\Events\IncidentResolvedEvent;
use Modules\ServiceManagement\Models\Incident;

final readonly class IncidentManagementService implements IncidentManagementServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function createIncident(CreateIncidentDTO $dto, ?string $actorUserId = null): Incident
    {
        return DB::transaction(function () use ($dto) {
            $incidentNumber = 'INC-'.date('Ymd').'-'.strtoupper(Str::random(6));

            $incident = Incident::create([
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId,
                'incident_number' => $incidentNumber,
                'title' => $dto->title,
                'summary' => $dto->description ?? $dto->title,
                'severity' => $dto->severity,
                'impact' => $dto->impact,
                'urgency' => $dto->urgency,
                'status' => 'investigating',
                'reported_at' => now(),
            ]);

            $this->auditLedger->record(
                'INCIDENT_CREATED',
                $incident,
                null,
                ['incident_number' => $incidentNumber, 'severity' => $dto->severity],
                'MODULE_17_ESM'
            );

            $this->dispatcher->dispatch(new IncidentCreatedEvent(
                incidentId: $incident->id,
                incidentNumber: $incident->incident_number,
                title: $incident->title,
                severity: $incident->severity,
                impact: $incident->impact,
                urgency: $incident->urgency,
                tenantId: $incident->tenant_id,
                companyId: $incident->company_id
            ));

            return $incident;
        });
    }

    public function resolveIncident(string $incidentId, ?string $summary = null, ?string $actorUserId = null): Incident
    {
        return DB::transaction(function () use ($incidentId, $summary) {
            $incident = Incident::findOrFail($incidentId);

            if (in_array($incident->status, ['resolved', 'closed'], true)) {
                throw new InvalidArgumentException("Incident {$incident->incident_number} is already {$incident->status}.");
            }

            $oldValues = ['status' => $incident->status];

            $incident->update([
                'status' => 'resolved',
                'post_mortem_summary' => $summary,
                'resolved_at' => now(),
            ]);

            $this->auditLedger->record(
                'INCIDENT_RESOLVED',
                $incident,
                $oldValues,
                ['resolution_summary' => $summary],
                'MODULE_17_ESM'
            );

            $this->dispatcher->dispatch(new IncidentResolvedEvent(
                incidentId: $incident->id,
                incidentNumber: $incident->incident_number,
                resolutionSummary: $summary,
                tenantId: $incident->tenant_id,
                companyId: $incident->company_id
            ));

            return $incident;
        });
    }

    public function closeIncident(string $incidentId, ?string $actorUserId = null): Incident
    {
        return DB::transaction(function () use ($incidentId) {
            $incident = Incident::findOrFail($incidentId);

            if ($incident->status === 'closed') {
                return $incident;
            }

            $oldValues = ['status' => $incident->status];

            $incident->update([
                'status' => 'closed',
                'closed_at' => now(),
            ]);

            $this->auditLedger->record(
                'INCIDENT_CLOSED',
                $incident,
                $oldValues,
                [],
                'MODULE_17_ESM'
            );

            return $incident;
        });
    }

    public function linkToTicket(string $incidentId, string $ticketId): void
    {
        $incident = Incident::findOrFail($incidentId);
        $incident->update(['ticket_id' => $ticketId]);
    }
}
