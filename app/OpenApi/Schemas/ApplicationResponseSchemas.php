<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Response payloads of the module, derived from its API resources and models.
 */
#[OA\Schema(
    schema: 'PortalQuoteResource',
    description: 'JSON representation produced by PortalQuoteResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'quote_number'),
        new OA\Property(property: 'date', type: 'string', format: 'date'),
        new OA\Property(property: 'valid_until', type: 'string', format: 'date'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'total_net', type: 'number'),
        new OA\Property(property: 'total_tax', type: 'number'),
        new OA\Property(property: 'total_gross', type: 'number'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'is_accepted', type: 'boolean'),
        new OA\Property(property: 'is_rejected', type: 'boolean'),
        new OA\Property(property: 'signed_by'),
        new OA\Property(property: 'signed_at'),
        new OA\Property(property: 'notes', type: 'string'),
        new OA\Property(property: 'custom_text'),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'description', type: 'string'), new OA\Property(property: 'quantity', type: 'number'), new OA\Property(property: 'unit_price', type: 'number'), new OA\Property(property: 'tax_rate', type: 'number'), new OA\Property(property: 'discount_percentage', type: 'number'), new OA\Property(property: 'line_total', type: 'number')])),
    ]
)]
#[OA\Schema(
    schema: 'PortalInvoiceResource',
    description: 'JSON representation produced by PortalInvoiceResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'invoice_number'),
        new OA\Property(property: 'date', type: 'string', format: 'date'),
        new OA\Property(property: 'due_date', type: 'string', format: 'date'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'total_net', type: 'number'),
        new OA\Property(property: 'total_tax', type: 'number'),
        new OA\Property(property: 'total_gross', type: 'number'),
        new OA\Property(property: 'total_paid', type: 'number'),
        new OA\Property(property: 'payment_status'),
        new OA\Property(property: 'is_paid', type: 'boolean'),
        new OA\Property(property: 'notes', type: 'string'),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'description', type: 'string'), new OA\Property(property: 'quantity', type: 'number'), new OA\Property(property: 'unit_price', type: 'number'), new OA\Property(property: 'tax_rate', type: 'number'), new OA\Property(property: 'line_total', type: 'number')])),
    ]
)]
#[OA\Schema(
    schema: 'PortalServiceReportResource',
    description: 'JSON representation produced by PortalServiceReportResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'report_number'),
        new OA\Property(property: 'report_date', type: 'string', format: 'date'),
        new OA\Property(property: 'site_address'),
        new OA\Property(property: 'customer_notes'),
        new OA\Property(property: 'total_hours', type: 'number'),
        new OA\Property(property: 'is_signed', type: 'boolean'),
        new OA\Property(property: 'signed_at'),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'description', type: 'string'), new OA\Property(property: 'quantity', type: 'number'), new OA\Property(property: 'unit_of_measure'), new OA\Property(property: 'unit_price', type: 'number'), new OA\Property(property: 'line_total', type: 'number')])),
        new OA\Property(property: 'attachments', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'original_name'), new OA\Property(property: 'mime_type'), new OA\Property(property: 'size')])),
    ]
)]
#[OA\Schema(
    schema: 'PortalTicketResource',
    description: 'JSON representation produced by PortalTicketResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'subject'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'priority'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'created_at'),
        new OA\Property(property: 'messages', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'author_type'), new OA\Property(property: 'author_name'), new OA\Property(property: 'message'), new OA\Property(property: 'created_at')])),
        new OA\Property(property: 'attachments', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'original_name'), new OA\Property(property: 'mime_type'), new OA\Property(property: 'size')])),
    ]
)]
#[OA\Schema(
    schema: 'ServiceReportResource',
    description: 'JSON representation produced by ServiceReportResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'report_number'),
        new OA\Property(property: 'report_date', type: 'string', format: 'date'),
        new OA\Property(property: 'status_id', type: 'string'),
        new OA\Property(property: 'status', type: 'object', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'label', type: 'string')]),
        new OA\Property(property: 'contact_id', type: 'string'),
        new OA\Property(property: 'contact', type: 'object', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'email', type: 'string'), new OA\Property(property: 'phone', type: 'string')]),
        new OA\Property(property: 'ticket_id', type: 'string'),
        new OA\Property(property: 'ticket', type: 'object', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'subject'), new OA\Property(property: 'status')]),
        new OA\Property(property: 'project_id', type: 'string'),
        new OA\Property(property: 'technician_id', type: 'string'),
        new OA\Property(property: 'technician', type: 'object', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'email', type: 'string')]),
        new OA\Property(property: 'time_start'),
        new OA\Property(property: 'time_end'),
        new OA\Property(property: 'total_hours', type: 'number'),
        new OA\Property(property: 'travel_distance_km', type: 'number'),
        new OA\Property(property: 'site_address'),
        new OA\Property(property: 'customer_notes'),
        new OA\Property(property: 'internal_notes'),
        new OA\Property(property: 'total_net', type: 'number'),
        new OA\Property(property: 'total_tax', type: 'number'),
        new OA\Property(property: 'total_gross', type: 'number'),
        new OA\Property(property: 'is_portal_visible', type: 'boolean'),
        new OA\Property(property: 'signed_at'),
        new OA\Property(property: 'invoiced_at'),
        new OA\Property(property: 'is_signed', type: 'boolean'),
        new OA\Property(property: 'is_invoiced', type: 'boolean'),
        new OA\Property(property: 'invoice_id', type: 'string'),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(ref: '#/components/schemas/ServiceReportLineResource')),
        new OA\Property(property: 'signatures', type: 'array', items: new OA\Items(ref: '#/components/schemas/ServiceReportSignatureResource')),
        new OA\Property(property: 'attachments', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'original_name'), new OA\Property(property: 'mime_type'), new OA\Property(property: 'size'), new OA\Property(property: 'created_at')])),
        new OA\Property(property: 'created_at'),
        new OA\Property(property: 'updated_at'),
    ]
)]
#[OA\Schema(
    schema: 'ServiceReportLineResource',
    description: 'JSON representation produced by ServiceReportLineResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'service_report_id', type: 'string'),
        new OA\Property(property: 'line_type_id', type: 'string'),
        new OA\Property(property: 'line_type', type: 'object', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'label', type: 'string')]),
        new OA\Property(property: 'item_id', type: 'string'),
        new OA\Property(property: 'item', type: 'object', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'name', type: 'string')]),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'quantity', type: 'number'),
        new OA\Property(property: 'unit_of_measure'),
        new OA\Property(property: 'unit_price', type: 'number'),
        new OA\Property(property: 'discount_percent', type: 'number'),
        new OA\Property(property: 'vat_code_id', type: 'string'),
        new OA\Property(property: 'tax_rate', type: 'number'),
        new OA\Property(property: 'line_net', type: 'number'),
        new OA\Property(property: 'line_tax', type: 'number'),
        new OA\Property(property: 'line_total', type: 'number'),
        new OA\Property(property: 'is_billable', type: 'boolean', nullable: true),
        new OA\Property(property: 'notes', type: 'string'),
        new OA\Property(property: 'created_at'),
        new OA\Property(property: 'updated_at'),
    ]
)]
#[OA\Schema(
    schema: 'ServiceReportSignatureResource',
    description: 'JSON representation produced by ServiceReportSignatureResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'signer_type'),
        new OA\Property(property: 'signer_name'),
        new OA\Property(property: 'signer_role'),
        new OA\Property(property: 'signer_email'),
        new OA\Property(property: 'signature_hash'),
        new OA\Property(property: 'signed_at'),
        new OA\Property(property: 'ip_address'),
    ]
)]
#[OA\Schema(
    schema: 'AppointmentResource',
    description: 'JSON representation produced by AppointmentResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'appointment_number'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'status', type: 'object', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'label', type: 'string')]),
        new OA\Property(property: 'contact', type: 'object', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'email', type: 'string'), new OA\Property(property: 'phone', type: 'string')]),
        new OA\Property(property: 'ticket', type: 'object', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'subject'), new OA\Property(property: 'priority'), new OA\Property(property: 'status')]),
        new OA\Property(property: 'service_report_id', type: 'string'),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'ends_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'all_day', type: 'boolean', nullable: true),
        new OA\Property(property: 'address'),
        new OA\Property(property: 'latitude', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'longitude', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'estimated_travel_minutes', type: 'integer', nullable: true),
        new OA\Property(property: 'estimated_duration_minutes', type: 'integer', nullable: true),
        new OA\Property(property: 'technician_notes'),
        new OA\Property(property: 'customer_notes'),
        new OA\Property(property: 'is_portal_visible', type: 'boolean', nullable: true),
        new OA\Property(property: 'reminder_sent_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'confirmed_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'cancelled_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'cancellation_reason'),
        new OA\Property(property: 'attendees', type: 'array', items: new OA\Items(ref: '#/components/schemas/AppointmentAttendeeResource')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'AppointmentAttendeeResource',
    description: 'JSON representation produced by AppointmentAttendeeResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'user_id', type: 'string'),
        new OA\Property(property: 'role'),
        new OA\Property(property: 'is_organizer', type: 'boolean', nullable: true),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'user', type: 'object', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'email', type: 'string')]),
    ]
)]
final class ApplicationResponseSchemas {}
