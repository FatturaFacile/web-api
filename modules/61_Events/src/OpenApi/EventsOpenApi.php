<?php

declare(strict_types=1);

namespace Modules\Events\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses, payloads and records of the event management endpoints.
 */
#[OA\Tag(name: 'Events', description: 'Event management: events with sessions and ticket types, registrations with QR code token, check-in, capacity and waiting list, revenue report')]
#[OA\Response(response: 'EventsUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'EventsForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'EventsNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'EventsUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'ManagedEvent', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'venue', type: 'string', nullable: true),
    new OA\Property(property: 'starts_at', type: 'string', example: '2027-09-10 09:00:00'),
    new OA\Property(property: 'ends_at', type: 'string', example: '2027-09-10 18:00:00'),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'published', 'closed', 'cancelled']),
    new OA\Property(property: 'capacity', description: 'Unlimited when empty', type: 'integer', nullable: true),
    new OA\Property(property: 'waitlist_enabled', type: 'boolean'),
    new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'confirmed_count', description: 'List and show only', type: 'integer'),
    new OA\Property(property: 'waitlisted_count', description: 'List and show only', type: 'integer'),
    new OA\Property(property: 'sessions', description: 'Show only', type: 'array', items: new OA\Items(ref: '#/components/schemas/EventSession')),
    new OA\Property(property: 'ticket_types', description: 'Show only', type: 'array', items: new OA\Items(ref: '#/components/schemas/EventTicketType')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'ManagedEventRequest', type: 'object', required: ['code', 'name', 'starts_at', 'ends_at'], properties: [
    new OA\Property(property: 'code', description: 'Unique in the company', type: 'string', maxLength: 40),
    new OA\Property(property: 'name', type: 'string', maxLength: 160),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'venue', type: 'string', nullable: true),
    new OA\Property(property: 'starts_at', type: 'string', example: '2027-09-10 09:00:00'),
    new OA\Property(property: 'ends_at', type: 'string', example: '2027-09-10 18:00:00'),
    new OA\Property(property: 'capacity', type: 'integer', nullable: true),
    new OA\Property(property: 'waitlist_enabled', type: 'boolean'),
    new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'EventSession', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'event_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'speaker', type: 'string', nullable: true),
    new OA\Property(property: 'room', type: 'string', nullable: true),
    new OA\Property(property: 'starts_at', type: 'string', example: '2027-09-10 10:00:00'),
    new OA\Property(property: 'ends_at', type: 'string', example: '2027-09-10 11:00:00'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'EventSessionRequest', type: 'object', required: ['event_id', 'title', 'starts_at', 'ends_at'], properties: [
    new OA\Property(property: 'event_id', description: 'Required on creation, fixed afterwards', type: 'string', format: 'uuid'),
    new OA\Property(property: 'title', type: 'string', maxLength: 160),
    new OA\Property(property: 'speaker', type: 'string', nullable: true),
    new OA\Property(property: 'room', type: 'string', nullable: true),
    new OA\Property(property: 'starts_at', type: 'string', example: '2027-09-10 10:00:00'),
    new OA\Property(property: 'ends_at', type: 'string', example: '2027-09-10 11:00:00'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'EventTicketType', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'event_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'price', type: 'string', example: '50.00'),
    new OA\Property(property: 'quantity', description: 'Unlimited when empty', type: 'integer', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'sold_count', description: 'Confirmed registrations', type: 'integer'),
])]
#[OA\Schema(schema: 'EventTicketTypeRequest', type: 'object', required: ['event_id', 'name', 'price'], properties: [
    new OA\Property(property: 'event_id', description: 'Required on creation, fixed afterwards', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string', maxLength: 120),
    new OA\Property(property: 'price', type: 'string', example: '50'),
    new OA\Property(property: 'quantity', type: 'integer', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'EventRegistration', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'event_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'event', type: 'object', nullable: true, properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'name', type: 'string')]),
    new OA\Property(property: 'ticket_type_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'ticket_type', type: 'object', nullable: true, properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'name', type: 'string')]),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'attendee_name', type: 'string'),
    new OA\Property(property: 'attendee_email', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['confirmed', 'waitlisted', 'cancelled']),
    new OA\Property(property: 'qr_token', type: 'string'),
    new OA\Property(property: 'price', type: 'string', example: '50.00'),
    new OA\Property(property: 'registered_at', type: 'string', example: '2027-06-15 09:00:00'),
    new OA\Property(property: 'paid_at', type: 'string', nullable: true),
    new OA\Property(property: 'checked_in_at', type: 'string', nullable: true),
    new OA\Property(property: 'cancelled_at', type: 'string', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'EventRegistrationRequest', type: 'object', required: ['event_id', 'ticket_type_id', 'attendee_name'], properties: [
    new OA\Property(property: 'event_id', description: 'Required on creation, fixed afterwards', type: 'string', format: 'uuid'),
    new OA\Property(property: 'ticket_type_id', description: 'Required on creation, fixed afterwards', type: 'string', format: 'uuid'),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'attendee_name', type: 'string', maxLength: 160),
    new OA\Property(property: 'attendee_email', type: 'string', format: 'email', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'EventRegistrationQr', type: 'object', properties: [
    new OA\Property(property: 'qr_token', type: 'string'),
    new OA\Property(property: 'data_uri', description: 'PNG image as a data URI', type: 'string'),
])]
#[OA\Schema(schema: 'EventCheckInRequest', type: 'object', required: ['qr_token'], properties: [
    new OA\Property(property: 'qr_token', type: 'string', maxLength: 64),
    new OA\Property(property: 'event_id', description: 'Refuses tokens of other events', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'ManagedEventReport', type: 'object', properties: [
    new OA\Property(property: 'event_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'capacity', type: 'integer', nullable: true),
    new OA\Property(property: 'confirmed', type: 'integer'),
    new OA\Property(property: 'waitlisted', type: 'integer'),
    new OA\Property(property: 'cancelled', type: 'integer'),
    new OA\Property(property: 'checked_in', type: 'integer'),
    new OA\Property(property: 'no_show', description: 'Confirmed registrations not checked in', type: 'integer'),
    new OA\Property(property: 'occupancy_percent', type: 'string', nullable: true, example: '85.00'),
    new OA\Property(property: 'expected_revenue', type: 'string'),
    new OA\Property(property: 'collected_revenue', type: 'string'),
    new OA\Property(property: 'outstanding_revenue', type: 'string'),
    new OA\Property(property: 'by_ticket_type', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'ticket_type_id', type: 'string', format: 'uuid'), new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'price', type: 'string'), new OA\Property(property: 'quantity', type: 'integer', nullable: true),
        new OA\Property(property: 'sold', type: 'integer'), new OA\Property(property: 'waitlisted', type: 'integer'),
        new OA\Property(property: 'expected_revenue', type: 'string'), new OA\Property(property: 'collected_revenue', type: 'string'),
    ])),
])]
final class EventsOpenApi {}
