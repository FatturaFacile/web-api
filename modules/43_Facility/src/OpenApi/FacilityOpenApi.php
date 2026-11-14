<?php

declare(strict_types=1);

namespace Modules\Facility\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses and schemas of the facility management endpoints.
 */
#[OA\Tag(name: 'Facility', description: 'Facility management and workplace: buildings and spaces, room and desk bookings, service requests, recurring inspections and utility meters')]
#[OA\Response(response: 'FacilityUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'FacilityForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'FacilityNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'FacilityUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'FacilityBuilding', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'city', type: 'string', nullable: true),
    new OA\Property(property: 'country', description: 'ISO 3166-1 alpha-2', type: 'string', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'FacilityBuildingRequest', type: 'object', required: ['code', 'name'], properties: [
    new OA\Property(property: 'code', description: 'Unique in the company', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'city', type: 'string', nullable: true),
    new OA\Property(property: 'country', type: 'string', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'FacilitySpace', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'building_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'parent_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'kind', type: 'string', enum: ['floor', 'office', 'meeting_room', 'desk', 'parking', 'warehouse', 'common_area']),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'floor_level', type: 'integer', nullable: true),
    new OA\Property(property: 'capacity', type: 'integer', nullable: true),
    new OA\Property(property: 'area_sqm', type: 'string', pattern: '^-?[0-9]+(\\.[0-9]+)?$', nullable: true),
    new OA\Property(property: 'is_bookable', type: 'boolean'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'amenities', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'building', type: 'object', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'FacilitySpaceNode', description: 'A space with its descendants.', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'building_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'parent_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'kind', type: 'string', enum: ['floor', 'office', 'meeting_room', 'desk', 'parking', 'warehouse', 'common_area']),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'capacity', type: 'integer', nullable: true),
    new OA\Property(property: 'is_bookable', type: 'boolean'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'children', description: 'Child spaces, same shape', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'FacilitySpaceRequest', type: 'object', required: ['building_id', 'kind', 'code', 'name'], properties: [
    new OA\Property(property: 'building_id', description: 'Never changes after creation', type: 'string', format: 'uuid'),
    new OA\Property(property: 'parent_id', description: 'Floors have no parent; desks, parking spots and meeting rooms cannot be parents', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'kind', type: 'string', enum: ['floor', 'office', 'meeting_room', 'desk', 'parking', 'warehouse', 'common_area']),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'floor_level', type: 'integer', nullable: true),
    new OA\Property(property: 'capacity', description: 'Required for bookable spaces', type: 'integer', nullable: true),
    new OA\Property(property: 'area_sqm', type: 'number', nullable: true),
    new OA\Property(property: 'is_bookable', description: 'Only office, meeting_room, desk, parking and warehouse can be booked', type: 'boolean'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'amenities', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'FacilityBookingRule', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'space_kind', type: 'string', enum: ['office', 'meeting_room', 'desk', 'parking', 'warehouse']),
    new OA\Property(property: 'max_bookings_per_user_per_day', type: 'integer', nullable: true),
    new OA\Property(property: 'max_minutes_per_booking', type: 'integer', nullable: true),
    new OA\Property(property: 'max_advance_days', type: 'integer', nullable: true),
    new OA\Property(property: 'checkin_grace_minutes', type: 'integer'),
    new OA\Property(property: 'requires_checkin', type: 'boolean'),
])]
#[OA\Schema(schema: 'FacilityBookingRuleEffective', type: 'object', properties: [
    new OA\Property(property: 'space_kind', type: 'string', enum: ['office', 'meeting_room', 'desk', 'parking', 'warehouse']),
    new OA\Property(property: 'max_bookings_per_user_per_day', type: 'integer', nullable: true),
    new OA\Property(property: 'max_minutes_per_booking', type: 'integer', nullable: true),
    new OA\Property(property: 'max_advance_days', type: 'integer', nullable: true),
    new OA\Property(property: 'checkin_grace_minutes', type: 'integer'),
    new OA\Property(property: 'requires_checkin', type: 'boolean'),
    new OA\Property(property: 'customized', description: 'False while the configured default applies', type: 'boolean'),
])]
#[OA\Schema(schema: 'FacilityBookingRuleRequest', type: 'object', required: ['checkin_grace_minutes', 'requires_checkin'], properties: [
    new OA\Property(property: 'max_bookings_per_user_per_day', type: 'integer', nullable: true),
    new OA\Property(property: 'max_minutes_per_booking', type: 'integer', nullable: true),
    new OA\Property(property: 'max_advance_days', type: 'integer', nullable: true),
    new OA\Property(property: 'checkin_grace_minutes', type: 'integer'),
    new OA\Property(property: 'requires_checkin', type: 'boolean'),
])]
#[OA\Schema(schema: 'FacilityBooking', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'space_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'series_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'user', type: 'object', nullable: true),
    new OA\Property(property: 'space', type: 'object', nullable: true),
    new OA\Property(property: 'title', type: 'string', nullable: true),
    new OA\Property(property: 'attendees', type: 'integer', nullable: true),
    new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'ends_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'status', type: 'string', enum: ['booked', 'checked_in', 'completed', 'cancelled', 'no_show']),
    new OA\Property(property: 'checked_in_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'released_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'cancelled_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'cancel_reason', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'FacilityBookingRequest', type: 'object', required: ['space_id', 'starts_at', 'ends_at'], properties: [
    new OA\Property(property: 'space_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'user_id', description: 'Book for another member (facility managers only)', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'ends_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'title', type: 'string', nullable: true),
    new OA\Property(property: 'attendees', type: 'integer', nullable: true),
])]
#[OA\Schema(schema: 'FacilityBookingCheckRequest', type: 'object', required: ['space_id', 'starts_at', 'ends_at'], properties: [
    new OA\Property(property: 'space_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'booking_id', description: 'A booking to leave out of the conflict check', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'ends_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'attendees', type: 'integer', nullable: true),
])]
#[OA\Schema(schema: 'FacilityBookingCheckResult', type: 'object', properties: [
    new OA\Property(property: 'available', type: 'boolean'),
    new OA\Property(property: 'violations', description: 'Each {code, field, message}; codes: not_bookable, in_past, too_long, too_far, over_capacity, daily_limit, conflict', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'conflicts', type: 'array', items: new OA\Items(ref: '#/components/schemas/FacilityBooking')),
    new OA\Property(property: 'rules', description: 'The limits that apply to the kind of space', type: 'object'),
])]
#[OA\Schema(schema: 'FacilityBookingCancelRequest', type: 'object', properties: [
    new OA\Property(property: 'reason', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'FacilityBookingSeries', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'space_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'title', type: 'string', nullable: true),
    new OA\Property(property: 'recurrence', type: 'string', enum: ['daily', 'weekly']),
    new OA\Property(property: 'weekdays', description: 'ISO weekdays, 1 Monday to 7 Sunday', type: 'array', items: new OA\Items(type: 'integer')),
    new OA\Property(property: 'start_date', type: 'string', format: 'date'),
    new OA\Property(property: 'until_date', type: 'string', format: 'date'),
    new OA\Property(property: 'start_time', description: 'H:i wall clock time of the company', type: 'string'),
    new OA\Property(property: 'end_time', type: 'string'),
    new OA\Property(property: 'status', type: 'string', enum: ['active', 'cancelled']),
    new OA\Property(property: 'occurrences', type: 'integer'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'FacilityBookingSeriesRequest', type: 'object', required: ['space_id', 'recurrence', 'start_date', 'until_date', 'start_time', 'end_time'], properties: [
    new OA\Property(property: 'space_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'recurrence', type: 'string', enum: ['daily', 'weekly']),
    new OA\Property(property: 'weekdays', description: 'Required for weekly', type: 'array', items: new OA\Items(type: 'integer'), nullable: true),
    new OA\Property(property: 'start_date', type: 'string', format: 'date'),
    new OA\Property(property: 'until_date', type: 'string', format: 'date'),
    new OA\Property(property: 'start_time', type: 'string'),
    new OA\Property(property: 'end_time', type: 'string'),
    new OA\Property(property: 'title', type: 'string', nullable: true),
    new OA\Property(property: 'skip_conflicts', description: 'Book the free occurrences and report the others', type: 'boolean'),
])]
#[OA\Schema(schema: 'FacilityBookingSeriesResult', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'space_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'status', type: 'string'),
    new OA\Property(property: 'occurrences', type: 'integer'),
    new OA\Property(property: 'created', type: 'integer'),
    new OA\Property(property: 'skipped', description: 'Each {date, reasons}', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'FacilityServiceRequest', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'number', description: 'SR-2027-0001', type: 'string'),
    new OA\Property(property: 'category', type: 'string', enum: ['cleaning', 'repair', 'it', 'security', 'hvac', 'other']),
    new OA\Property(property: 'priority', type: 'string', enum: ['low', 'normal', 'high', 'urgent']),
    new OA\Property(property: 'status', type: 'string', enum: ['new', 'assigned', 'in_progress', 'on_hold', 'resolved', 'closed', 'cancelled']),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'building_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'space_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'requester_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'assignee_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'requester', type: 'object', nullable: true),
    new OA\Property(property: 'assignee', type: 'object', nullable: true),
    new OA\Property(property: 'asset_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'work_order_id', description: 'Maintenance work order of the asset management module', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'inspection_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'due_at', description: 'SLA due date', type: 'string', format: 'date-time'),
    new OA\Property(property: 'is_overdue', type: 'boolean'),
    new OA\Property(property: 'assigned_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'responded_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'resolved_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'hold_reason', type: 'string', nullable: true),
    new OA\Property(property: 'resolution_notes', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'FacilityServiceRequestRequest', type: 'object', required: ['category', 'title'], properties: [
    new OA\Property(property: 'category', type: 'string', enum: ['cleaning', 'repair', 'it', 'security', 'hvac', 'other']),
    new OA\Property(property: 'priority', type: 'string', enum: ['low', 'normal', 'high', 'urgent']),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'building_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'space_id', description: 'The building is taken from the space', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'asset_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'FacilityServiceRequestBoard', type: 'object', properties: [
    new OA\Property(property: 'by_status', type: 'object'),
    new OA\Property(property: 'by_priority', description: 'Open requests only', type: 'object'),
    new OA\Property(property: 'overdue', type: 'integer'),
    new OA\Property(property: 'open', type: 'integer'),
])]
#[OA\Schema(schema: 'FacilityAssignRequest', type: 'object', required: ['assignee_id'], properties: [
    new OA\Property(property: 'assignee_id', type: 'string', format: 'uuid'),
])]
#[OA\Schema(schema: 'FacilityReasonRequest', type: 'object', required: ['reason'], properties: [
    new OA\Property(property: 'reason', type: 'string'),
])]
#[OA\Schema(schema: 'FacilityResolveRequest', type: 'object', required: ['resolution_notes'], properties: [
    new OA\Property(property: 'resolution_notes', type: 'string'),
])]
#[OA\Schema(schema: 'FacilityLinkWorkOrderRequest', type: 'object', required: ['work_order_id'], properties: [
    new OA\Property(property: 'work_order_id', type: 'string', format: 'uuid'),
])]
#[OA\Schema(schema: 'FacilityRaiseWorkOrderRequest', type: 'object', properties: [
    new OA\Property(property: 'asset_id', description: 'Needed only when the request has no asset', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'FacilityRequestComment', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'service_request_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'author_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'author', type: 'object', nullable: true),
    new OA\Property(property: 'body', type: 'string'),
    new OA\Property(property: 'is_internal', type: 'boolean'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'FacilityRequestCommentRequest', type: 'object', required: ['body'], properties: [
    new OA\Property(property: 'body', type: 'string'),
    new OA\Property(property: 'is_internal', description: 'Operators only', type: 'boolean'),
])]
#[OA\Schema(schema: 'FacilityChecklistItem', type: 'object', required: ['label'], properties: [
    new OA\Property(property: 'key', description: 'Generated from the label when empty', type: 'string', nullable: true),
    new OA\Property(property: 'label', type: 'string'),
    new OA\Property(property: 'type', type: 'string', enum: ['pass_fail', 'number', 'text']),
    new OA\Property(property: 'required', type: 'boolean'),
])]
#[OA\Schema(schema: 'FacilityInspectionTemplate', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'building_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'space_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'frequency', type: 'string', enum: ['daily', 'weekly', 'monthly', 'quarterly', 'yearly']),
    new OA\Property(property: 'interval_count', type: 'integer'),
    new OA\Property(property: 'checklist', type: 'array', items: new OA\Items(ref: '#/components/schemas/FacilityChecklistItem')),
    new OA\Property(property: 'assignee_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'next_due_date', type: 'string', format: 'date'),
    new OA\Property(property: 'lead_days', type: 'integer'),
    new OA\Property(property: 'last_generated_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'FacilityInspectionTemplateRequest', type: 'object', required: ['name', 'frequency', 'checklist', 'next_due_date'], properties: [
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'building_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'space_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'frequency', type: 'string', enum: ['daily', 'weekly', 'monthly', 'quarterly', 'yearly']),
    new OA\Property(property: 'interval_count', type: 'integer'),
    new OA\Property(property: 'checklist', type: 'array', items: new OA\Items(ref: '#/components/schemas/FacilityChecklistItem')),
    new OA\Property(property: 'assignee_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'next_due_date', type: 'string', format: 'date'),
    new OA\Property(property: 'lead_days', description: 'Generate this many days before the due date, 0 to 60', type: 'integer'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'FacilityGenerateInspectionsRequest', type: 'object', properties: [
    new OA\Property(property: 'as_of', description: 'Default today', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'template_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'FacilityInspection', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'template_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'number', description: 'INS-2027-0001', type: 'string'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'building_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'space_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'assignee_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'due_date', type: 'string', format: 'date'),
    new OA\Property(property: 'status', type: 'string', enum: ['scheduled', 'in_progress', 'completed', 'cancelled']),
    new OA\Property(property: 'result', type: 'string', enum: ['pass', 'fail'], nullable: true),
    new OA\Property(property: 'checklist', description: 'Items with result, value and notes', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'completed_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'service_request_id', description: 'Raised when an item failed', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'FacilityInspectionAnswer', type: 'object', required: ['key'], properties: [
    new OA\Property(property: 'key', type: 'string'),
    new OA\Property(property: 'result', type: 'string', enum: ['pass', 'fail', 'na'], nullable: true),
    new OA\Property(property: 'value', type: 'string', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'FacilityCompleteInspectionRequest', type: 'object', required: ['answers'], properties: [
    new OA\Property(property: 'answers', type: 'array', items: new OA\Items(ref: '#/components/schemas/FacilityInspectionAnswer')),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'FacilityMeter', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'building_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'space_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'kind', type: 'string', enum: ['electricity', 'gas', 'water']),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'unit', type: 'string'),
    new OA\Property(property: 'tariff_per_unit', type: 'string', pattern: '^-?[0-9]+(\\.[0-9]+)?$', nullable: true),
    new OA\Property(property: 'currency', type: 'string', enum: ['EUR', 'CHF']),
    new OA\Property(property: 'rollover_value', type: 'string', pattern: '^-?[0-9]+(\\.[0-9]+)?$', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'installed_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'last_read_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'last_value', type: 'string', pattern: '^-?[0-9]+(\\.[0-9]+)?$', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'FacilityMeterRequest', type: 'object', required: ['building_id', 'kind', 'code', 'name', 'unit'], properties: [
    new OA\Property(property: 'building_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'space_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'kind', type: 'string', enum: ['electricity', 'gas', 'water']),
    new OA\Property(property: 'code', description: 'Unique in the company', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'unit', description: 'electricity: kWh, MWh; gas: Sm3, m3, kWh; water: m3, l', type: 'string'),
    new OA\Property(property: 'tariff_per_unit', type: 'number', nullable: true),
    new OA\Property(property: 'currency', type: 'string', enum: ['EUR', 'CHF']),
    new OA\Property(property: 'rollover_value', description: 'Dial value at which the meter wraps to zero', type: 'number', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'installed_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'FacilityMeterReading', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'meter_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'read_on', type: 'string', format: 'date'),
    new OA\Property(property: 'value', type: 'string', pattern: '^-?[0-9]+(\\.[0-9]+)?$'),
    new OA\Property(property: 'consumption', description: 'Since the previous reading; zero for the first one', type: 'string', pattern: '^-?[0-9]+(\\.[0-9]+)?$'),
    new OA\Property(property: 'cost', type: 'string', pattern: '^-?[0-9]+(\\.[0-9]+)?$', nullable: true),
    new OA\Property(property: 'currency', type: 'string', nullable: true),
    new OA\Property(property: 'reading_type', type: 'string', enum: ['regular', 'rollover', 'replacement']),
    new OA\Property(property: 'recorded_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'FacilityMeterReadingRequest', type: 'object', required: ['read_on', 'value'], properties: [
    new OA\Property(property: 'read_on', description: 'After the date of the last reading, not in the future', type: 'string', format: 'date'),
    new OA\Property(property: 'value', type: 'number'),
    new OA\Property(property: 'reading_type', type: 'string', enum: ['regular', 'rollover', 'replacement']),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'FacilityMeterSummary', type: 'object', properties: [
    new OA\Property(property: 'year', type: 'integer'),
    new OA\Property(property: 'rows', description: 'Each {month, kind, unit, currency, consumption, cost, readings}', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'totals', description: 'Each {kind, unit, currency, consumption, cost}', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'FacilityOverview', type: 'object', properties: [
    new OA\Property(property: 'buildings', type: 'integer'),
    new OA\Property(property: 'spaces', type: 'integer'),
    new OA\Property(property: 'bookable_spaces', type: 'integer'),
    new OA\Property(property: 'bookings_today', type: 'integer'),
    new OA\Property(property: 'my_upcoming_bookings', type: 'integer'),
    new OA\Property(property: 'requests', ref: '#/components/schemas/FacilityServiceRequestBoard'),
    new OA\Property(property: 'inspections', type: 'object'),
    new OA\Property(property: 'requests_sla_hours', type: 'object'),
])]
final class FacilityOpenApi {}
