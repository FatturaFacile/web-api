<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Contracts;

use Modules\ServiceManagement\DTOs\CreateIncidentDTO;
use Modules\ServiceManagement\Models\Incident;

interface IncidentManagementServiceInterface
{
    public function createIncident(CreateIncidentDTO $dto, ?string $actorUserId = null): Incident;

    public function resolveIncident(string $incidentId, ?string $summary = null, ?string $actorUserId = null): Incident;

    public function closeIncident(string $incidentId, ?string $actorUserId = null): Incident;

    public function linkToTicket(string $incidentId, string $ticketId): void;
}
