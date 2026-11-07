<?php

declare(strict_types=1);

namespace Modules\Visitors\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses, payloads and records of the visitor management endpoints.
 */
#[OA\Tag(name: 'Visitors', description: 'Visitor management: expected visits, check-in with badge, who is on site, reception kiosks')]
#[OA\Response(response: 'VisitorsUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'VisitorsForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'VisitorsNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'VisitorsUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Response(response: 'VisitorsKioskUnauthenticated', description: 'Unknown kiosk or wrong secret', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse'))]
#[OA\Response(response: 'VisitorsMaintenance', description: 'The company is being restored from a backup; retry later', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse'))]
#[OA\Schema(schema: 'VisitorsVisitorRequest', type: 'object', required: ['first_name', 'last_name'], properties: [
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'first_name', type: 'string', maxLength: 100),
    new OA\Property(property: 'last_name', type: 'string', maxLength: 100),
    new OA\Property(property: 'company_name', type: 'string', nullable: true),
    new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
    new OA\Property(property: 'phone', type: 'string', nullable: true),
    new OA\Property(property: 'document_number', description: 'Identity document; stored encrypted and never returned', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'VisitorsVisitRequest', description: 'Either visitor_id or visitor.', type: 'object', required: ['host_user_id', 'expected_at'], properties: [
    new OA\Property(property: 'visitor_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'visitor', ref: '#/components/schemas/VisitorsVisitorRequest', nullable: true),
    new OA\Property(property: 'host_user_id', description: 'Member of the company who receives the visitor; a member without reception rights can only name themselves', type: 'string', format: 'uuid'),
    new OA\Property(property: 'expected_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'expected_until', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'purpose', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'VisitorsCheckInRequest', type: 'object', required: ['badge_number'], properties: [
    new OA\Property(property: 'badge_number', type: 'string', maxLength: 30, example: 'V-012'),
])]
#[OA\Schema(schema: 'VisitorsKioskRequest', type: 'object', required: ['name'], properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 100, example: 'Reception ingresso nord'),
])]
#[OA\Schema(schema: 'VisitorsKioskCheckInRequest', description: 'An expected visit (visit_id) or a walk-in (visitor and host_user_id).', type: 'object', required: ['badge_number'], properties: [
    new OA\Property(property: 'visit_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'visitor', type: 'object', nullable: true, properties: [
        new OA\Property(property: 'first_name', type: 'string'), new OA\Property(property: 'last_name', type: 'string'),
        new OA\Property(property: 'company_name', type: 'string', nullable: true), new OA\Property(property: 'email', type: 'string', nullable: true),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
    ]),
    new OA\Property(property: 'host_user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'purpose', type: 'string', nullable: true),
    new OA\Property(property: 'badge_number', type: 'string', maxLength: 30),
])]
#[OA\Schema(schema: 'VisitorsKioskCheckOutRequest', type: 'object', required: ['badge_number'], properties: [
    new OA\Property(property: 'badge_number', type: 'string', maxLength: 30),
])]
#[OA\Schema(schema: 'VisitorsVisitor', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'first_name', type: 'string'),
    new OA\Property(property: 'last_name', type: 'string'),
    new OA\Property(property: 'full_name', type: 'string'),
    new OA\Property(property: 'company_name', type: 'string', nullable: true),
    new OA\Property(property: 'email', type: 'string', nullable: true),
    new OA\Property(property: 'phone', type: 'string', nullable: true),
    new OA\Property(property: 'has_document', type: 'boolean'),
    new OA\Property(property: 'last_visit_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'VisitorsVisit', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'visitor_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'visitor', ref: '#/components/schemas/VisitorsVisitor'),
    new OA\Property(property: 'host_user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'host', type: 'object', nullable: true, properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'name', type: 'string')]),
    new OA\Property(property: 'expected_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'expected_until', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'purpose', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['expected', 'checked_in', 'checked_out', 'cancelled']),
    new OA\Property(property: 'badge_number', type: 'string', nullable: true),
    new OA\Property(property: 'checked_in_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'checked_out_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'kiosk_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'host_notified_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'VisitorsKiosk', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'device_key', type: 'string'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'last_seen_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'secret', description: 'Only in the answer that creates or rotates the secret', type: 'string'),
])]
#[OA\Schema(schema: 'VisitorsKioskVisit', description: 'What a kiosk screen may show: no contact data, no company, no purpose.', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'visitor_name', description: 'First name and initial of the last name', type: 'string'),
    new OA\Property(property: 'host_name', type: 'string', nullable: true),
    new OA\Property(property: 'expected_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'status', type: 'string'),
    new OA\Property(property: 'badge_number', type: 'string', nullable: true),
    new OA\Property(property: 'checked_in_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'checked_out_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'VisitorsVisitorResponse', type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/VisitorsVisitor')])]
#[OA\Schema(schema: 'VisitorsVisitorCollection', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/VisitorsVisitor'))])]
#[OA\Schema(schema: 'VisitorsVisitResponse', type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/VisitorsVisit')])]
#[OA\Schema(schema: 'VisitorsVisitCollection', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/VisitorsVisit'))])]
#[OA\Schema(schema: 'VisitorsKioskResponse', type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/VisitorsKiosk')])]
#[OA\Schema(schema: 'VisitorsKioskCollection', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/VisitorsKiosk'))])]
#[OA\Schema(schema: 'VisitorsKioskVisitResponse', type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/VisitorsKioskVisit')])]
#[OA\Schema(schema: 'VisitorsKioskVisitCollection', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/VisitorsKioskVisit'))])]
final class VisitorsOpenApi {}
