<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request payloads of the module, derived from its form requests.
 */
#[OA\Schema(
    schema: 'ServiceManagementStoreTicketRequest',
    description: 'Request payload validated by StoreTicketRequest',
    required: ['title'],
    type: 'object',
    properties: [
        new OA\Property(property: 'title', type: 'string', maxLength: 255),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'customer_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'contact_email', type: 'string', format: 'email', maxLength: 180, nullable: true),
        new OA\Property(property: 'contact_phone', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'priority', type: 'string', nullable: true, enum: ['low', 'medium', 'high', 'critical']),
        new OA\Property(property: 'channel', type: 'string', nullable: true, enum: ['portal', 'email', 'phone', 'chat', 'api', 'field']),
        new OA\Property(property: 'service_category_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'service_contract_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'sla_policy_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'assigned_to_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'service_team_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'requester_user_id', description: 'Internal IT ticket: the colleague who asks for help', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'asset_id', description: 'Fixed asset concerned (device, workstation)', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'impact', description: 'With urgency, sets the priority through the matrix when no priority is given', type: 'string', enum: ['low', 'medium', 'high'], nullable: true),
        new OA\Property(property: 'urgency', type: 'string', enum: ['low', 'medium', 'high'], nullable: true),
        new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string', maxLength: 50), nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ServiceManagementAssignTicketRequest',
    description: 'Request payload validated by AssignTicketRequest',
    required: ['assigned_to_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'assigned_to_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'service_team_id', type: 'string', format: 'uuid', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ServiceManagementResolveTicketRequest',
    description: 'Request payload validated by ResolveTicketRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'resolution_notes', type: 'string', nullable: true),
        new OA\Property(property: 'root_cause', type: 'string', nullable: true),
        new OA\Property(property: 'knowledge_article_id', type: 'string', format: 'uuid', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ServiceManagementStoreIncidentRequest',
    description: 'Request payload validated by StoreIncidentRequest',
    required: ['title'],
    type: 'object',
    properties: [
        new OA\Property(property: 'title', type: 'string', maxLength: 255),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'severity', type: 'string', nullable: true, enum: ['low', 'medium', 'high', 'critical', 'catastrophic']),
        new OA\Property(property: 'impact', type: 'string', nullable: true, enum: ['minor', 'moderate', 'significant', 'extensive']),
        new OA\Property(property: 'urgency', type: 'string', nullable: true, enum: ['low', 'medium', 'high', 'immediate']),
        new OA\Property(property: 'service_category_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'affected_asset_id', type: 'string', format: 'uuid', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ServiceManagementStoreChangeRequestRequest',
    description: 'Request payload validated by StoreChangeRequestRequest',
    required: ['title'],
    type: 'object',
    properties: [
        new OA\Property(property: 'title', type: 'string', maxLength: 255),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'change_type', type: 'string', nullable: true, enum: ['standard', 'normal', 'emergency']),
        new OA\Property(property: 'risk_level', type: 'string', nullable: true, enum: ['low', 'medium', 'high', 'extreme']),
        new OA\Property(property: 'priority', type: 'string', nullable: true, enum: ['low', 'medium', 'high', 'critical']),
        new OA\Property(property: 'service_category_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'rollout_plan', type: 'string', nullable: true),
        new OA\Property(property: 'rollback_plan', type: 'string', nullable: true),
        new OA\Property(property: 'test_plan', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ServiceManagementStoreWorkOrderRequest',
    description: 'Request payload validated by StoreWorkOrderRequest',
    required: ['title'],
    type: 'object',
    properties: [
        new OA\Property(property: 'title', type: 'string', maxLength: 255),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'work_type', type: 'string', nullable: true, enum: ['corrective', 'preventive', 'inspection', 'installation', 'emergency', 'commissioning']),
        new OA\Property(property: 'priority', type: 'string', nullable: true, enum: ['low', 'medium', 'high', 'critical']),
        new OA\Property(property: 'service_category_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'customer_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'service_contract_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'asset_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'ticket_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'project_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'service_team_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'lead_technician_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'scheduled_start', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'scheduled_end', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'location_address', type: 'string', maxLength: 255, nullable: true),
        new OA\Property(property: 'billing_type', type: 'string', nullable: true, enum: ['contract_included', 'time_and_materials', 'fixed_fee', 'non_billable', 'internal_cost']),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items),
    ]
)]
#[OA\Schema(
    schema: 'ServiceManagementScheduleAppointmentRequest',
    description: 'Request payload validated by ScheduleAppointmentRequest',
    required: ['technician_id', 'scheduled_start', 'scheduled_end'],
    type: 'object',
    properties: [
        new OA\Property(property: 'technician_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'scheduled_start', type: 'string', format: 'date'),
        new OA\Property(property: 'scheduled_end', type: 'string', format: 'date', description: 'Must be after `scheduled_start`.'),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ServiceManagementRecordVisitRequest',
    description: 'Request payload validated by RecordVisitRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'technician_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'service_appointment_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'check_in_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'check_out_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'work_summary', type: 'string', nullable: true),
        new OA\Property(property: 'customer_feedback', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ServiceManagementSubmitCSATRequest',
    description: 'Request payload validated by SubmitCSATRequest',
    required: ['score'],
    type: 'object',
    properties: [
        new OA\Property(property: 'score', type: 'integer', minimum: 1, maximum: 5),
        new OA\Property(property: 'feedback', type: 'string', nullable: true),
        new OA\Property(property: 'ticket_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'work_order_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'customer_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'respondent_email', type: 'string', format: 'email', nullable: true),
    ]
)]
final class ServiceManagementRequestSchemas {}
