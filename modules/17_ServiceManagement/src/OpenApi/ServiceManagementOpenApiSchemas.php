<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'ServiceManagement',
    description: 'Enterprise Service Management, ITSM, and Field Service endpoints (Tickets, Incidents, Problems, Changes, Work Orders, Service Contracts)'
)]
#[OA\Schema(
    schema: 'TicketResource',
    title: 'Ticket Resource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'ticket_number', type: 'string', example: 'TCK-20261029-ABC123'),
        new OA\Property(property: 'title', type: 'string', example: 'Network latency in Milan data center'),
        new OA\Property(property: 'priority', type: 'string', enum: ['low', 'medium', 'high', 'critical']),
        new OA\Property(property: 'status', type: 'string', enum: ['open', 'assigned', 'in_progress', 'on_hold', 'escalated', 'resolved', 'closed', 'cancelled']),
        new OA\Property(property: 'is_sla_breached', type: 'boolean', example: false),
    ]
)]
#[OA\Schema(
    schema: 'WorkOrderResource',
    title: 'Work Order Resource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'work_order_number', type: 'string', example: 'WO-20261029-XYZ789'),
        new OA\Property(property: 'title', type: 'string', example: 'Emergency pump inspection'),
        new OA\Property(property: 'work_type', type: 'string', enum: ['corrective', 'preventive', 'inspection', 'installation', 'emergency', 'commissioning']),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'approved', 'scheduled', 'dispatched', 'in_progress', 'on_hold', 'completed', 'cancelled']),
        new OA\Property(property: 'total_cost', type: 'number', format: 'double', example: 1250.00),
    ]
)]
final class ServiceManagementOpenApiSchemas {}
