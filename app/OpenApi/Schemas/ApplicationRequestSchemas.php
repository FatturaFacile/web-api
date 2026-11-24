<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Request payloads of the module, derived from its form requests.
 */
#[OA\Schema(
    schema: 'ApprovePortalQuoteRequest',
    description: 'Request payload validated by ApprovePortalQuoteRequest',
    required: ['signer_name'],
    type: 'object',
    properties: [
        new OA\Property(property: 'signer_name', type: 'string', maxLength: 150),
        new OA\Property(property: 'signature_data', type: 'string', nullable: true),
        new OA\Property(property: 'notes', type: 'string', maxLength: 1000, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'RejectPortalQuoteRequest',
    description: 'Request payload validated by RejectPortalQuoteRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'reason', type: 'string', maxLength: 1000, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ReplyPortalTicketRequest',
    description: 'Request payload validated by ReplyPortalTicketRequest',
    required: ['message'],
    type: 'object',
    properties: [
        new OA\Property(property: 'message', type: 'string', maxLength: 5000),
        new OA\Property(property: 'author_name', type: 'string', maxLength: 150, nullable: true),
        new OA\Property(property: 'attachments', type: 'array', maxItems: 5, nullable: true, items: new OA\Items(type: 'string', format: 'binary')),
    ]
)]
#[OA\Schema(
    schema: 'UpdateServiceReportRequest',
    description: 'Request payload validated by UpdateServiceReportRequest',
    required: ['contact_id', 'technician_id', 'report_date'],
    type: 'object',
    properties: [
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'technician_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'ticket_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'project_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'report_date', type: 'string', format: 'date'),
        new OA\Property(property: 'status_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'time_start', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'time_end', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'travel_distance_km', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'site_address', type: 'string', maxLength: 255, nullable: true),
        new OA\Property(property: 'customer_notes', type: 'string', nullable: true),
        new OA\Property(property: 'internal_notes', type: 'string', nullable: true),
        new OA\Property(property: 'is_portal_visible', type: 'boolean', nullable: true),
        new OA\Property(property: 'lines', type: 'array', nullable: true, items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'line_type_id', type: 'string', format: 'uuid', description: 'Required with `lines`.'), new OA\Property(property: 'item_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'description', type: 'string', maxLength: 255, description: 'Required with `lines`.'), new OA\Property(property: 'quantity', type: 'number', minimum: 0.0001, description: 'Required with `lines`.'), new OA\Property(property: 'unit_of_measure', type: 'string', maxLength: 20, nullable: true), new OA\Property(property: 'unit_price', type: 'number', minimum: 0, description: 'Required with `lines`.'), new OA\Property(property: 'discount_percent', type: 'number', minimum: 0, maximum: 100, nullable: true), new OA\Property(property: 'vat_code_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'tax_rate', type: 'number', minimum: 0, maximum: 100, nullable: true), new OA\Property(property: 'is_billable', type: 'boolean', nullable: true), new OA\Property(property: 'notes', type: 'string', nullable: true)])),
    ]
)]
#[OA\Schema(
    schema: 'UpdateAppointmentRequest',
    description: 'Request payload validated by UpdateAppointmentRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'ticket_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'status_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'title', type: 'string', maxLength: 255),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date'),
        new OA\Property(property: 'ends_at', type: 'string', format: 'date', description: 'Must be after `starts_at`.'),
        new OA\Property(property: 'all_day', type: 'boolean', nullable: true),
        new OA\Property(property: 'address', type: 'string', maxLength: 255, nullable: true),
        new OA\Property(property: 'latitude', type: 'number', minimum: -90, maximum: 90, nullable: true),
        new OA\Property(property: 'longitude', type: 'number', minimum: -180, maximum: 180, nullable: true),
        new OA\Property(property: 'estimated_travel_minutes', type: 'integer', minimum: 0, maximum: 1440, nullable: true),
        new OA\Property(property: 'estimated_duration_minutes', type: 'integer', minimum: 1, nullable: true),
        new OA\Property(property: 'technician_notes', type: 'string', nullable: true),
        new OA\Property(property: 'customer_notes', type: 'string', nullable: true),
        new OA\Property(property: 'is_portal_visible', type: 'boolean', nullable: true),
        new OA\Property(property: 'allow_conflicts', type: 'boolean', nullable: true),
        new OA\Property(property: 'attendees', type: 'array', nullable: true, items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'user_id', type: 'string', format: 'uuid', description: 'Required with `attendees`.'), new OA\Property(property: 'role', type: 'string', nullable: true, enum: ['lead', 'technician', 'assistant']), new OA\Property(property: 'is_organizer', type: 'boolean', nullable: true), new OA\Property(property: 'status', type: 'string', nullable: true, enum: ['accepted', 'pending', 'declined'])])),
    ]
)]
final class ApplicationRequestSchemas {}
