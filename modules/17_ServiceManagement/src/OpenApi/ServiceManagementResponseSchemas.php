<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Response payloads of the module, derived from its API resources and models.
 */
#[OA\Schema(
    schema: 'ServiceManagementTicketResource',
    description: 'JSON representation produced by TicketResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'ticket_number'),
        new OA\Property(property: 'type', type: 'string'),
        new OA\Property(property: 'subject', type: 'string'),
        new OA\Property(property: 'title', type: 'string', description: 'Same as subject, kept for existing clients.'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'customer_id', type: 'string', nullable: true),
        new OA\Property(property: 'contact_id', type: 'string', nullable: true),
        new OA\Property(property: 'contact_email'),
        new OA\Property(property: 'contact_phone'),
        new OA\Property(property: 'priority'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'source'),
        new OA\Property(property: 'channel', description: 'Same as source, kept for existing clients.'),
        new OA\Property(property: 'category_id', type: 'string'),
        new OA\Property(property: 'service_category_id', type: 'string', description: 'Same as category_id, kept for existing clients.'),
        new OA\Property(property: 'service_contract_id', type: 'string'),
        new OA\Property(property: 'sla_policy_id', type: 'string'),
        new OA\Property(property: 'assigned_technician_id', type: 'string'),
        new OA\Property(property: 'assigned_to_id', type: 'string', description: 'Same as assigned_technician_id, kept for existing clients.'),
        new OA\Property(property: 'assigned_team_id', type: 'string'),
        new OA\Property(property: 'service_team_id', type: 'string', description: 'Same as assigned_team_id, kept for existing clients.'),
        new OA\Property(property: 'requester_user_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'asset_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'response_due_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'resolution_due_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'resolved_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'closed_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'is_sla_breached', type: 'boolean'),
        new OA\Property(property: 'is_escalated', type: 'boolean'),
        new OA\Property(property: 'escalation_level', type: 'integer'),
        new OA\Property(property: 'is_locked', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'ServiceManagementIncidentResource',
    description: 'JSON representation produced by IncidentResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'incident_number'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'severity'),
        new OA\Property(property: 'impact'),
        new OA\Property(property: 'urgency'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'service_category_id', type: 'string'),
        new OA\Property(property: 'affected_asset_id', type: 'string'),
        new OA\Property(property: 'reported_by_id', type: 'string'),
        new OA\Property(property: 'resolved_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'closed_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'is_major_incident', type: 'boolean'),
        new OA\Property(property: 'is_locked', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'ServiceManagementProblemModel',
    description: 'Attributes of the Problem record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'is_known_error', type: 'boolean', nullable: true),
        new OA\Property(property: 'resolved_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'linked_incident_ids'),
        new OA\Property(property: 'metadata'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'ServiceManagementChangeRequestResource',
    description: 'JSON representation produced by ChangeRequestResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'change_number'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'change_type'),
        new OA\Property(property: 'risk_level'),
        new OA\Property(property: 'priority'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'service_category_id', type: 'string'),
        new OA\Property(property: 'rollout_plan'),
        new OA\Property(property: 'rollback_plan'),
        new OA\Property(property: 'test_plan'),
        new OA\Property(property: 'cab_approved_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'is_locked', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'ServiceManagementWorkOrderResource',
    description: 'JSON representation produced by WorkOrderResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'work_order_number'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'work_type'),
        new OA\Property(property: 'priority'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'customer_id', type: 'string'),
        new OA\Property(property: 'service_contract_id', type: 'string'),
        new OA\Property(property: 'asset_id', type: 'string'),
        new OA\Property(property: 'ticket_id', type: 'string'),
        new OA\Property(property: 'project_id', type: 'string'),
        new OA\Property(property: 'service_team_id', type: 'string'),
        new OA\Property(property: 'lead_technician_id', type: 'string'),
        new OA\Property(property: 'scheduled_start', type: 'string', format: 'date-time'),
        new OA\Property(property: 'scheduled_end', type: 'string', format: 'date-time'),
        new OA\Property(property: 'actual_start', type: 'string', format: 'date-time'),
        new OA\Property(property: 'actual_end', type: 'string', format: 'date-time'),
        new OA\Property(property: 'location_address'),
        new OA\Property(property: 'billing_type'),
        new OA\Property(property: 'total_labor_hours', type: 'number'),
        new OA\Property(property: 'total_cost', type: 'number'),
        new OA\Property(property: 'total_billed_amount', type: 'number'),
        new OA\Property(property: 'is_invoice_ready', type: 'boolean'),
        new OA\Property(property: 'is_locked', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'ServiceManagementServiceAppointmentModel',
    description: 'Attributes of the Service Appointment record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'start_time', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'end_time', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'metadata'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'ServiceManagementServiceVisitModel',
    description: 'Attributes of the Service Visit record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'check_in_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'check_out_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'duration_minutes', type: 'integer', nullable: true),
        new OA\Property(property: 'customer_present', type: 'boolean', nullable: true),
        new OA\Property(property: 'is_successful', type: 'boolean', nullable: true),
        new OA\Property(property: 'location_check_in'),
        new OA\Property(property: 'location_check_out'),
        new OA\Property(property: 'metadata'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'ServiceManagementServiceContractResource',
    description: 'JSON representation produced by ServiceContractResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'contract_number'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'customer_id', type: 'string'),
        new OA\Property(property: 'contract_type'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'start_date', type: 'string'),
        new OA\Property(property: 'end_date', type: 'string'),
        new OA\Property(property: 'contract_value', type: 'number'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'is_locked', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'ServiceManagementCSATSurveyResource',
    description: 'JSON representation produced by CSATSurveyResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'score', type: 'integer'),
        new OA\Property(property: 'feedback'),
        new OA\Property(property: 'ticket_id', type: 'string'),
        new OA\Property(property: 'work_order_id', type: 'string'),
        new OA\Property(property: 'customer_id', type: 'string'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'responded_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
final class ServiceManagementResponseSchemas {}
