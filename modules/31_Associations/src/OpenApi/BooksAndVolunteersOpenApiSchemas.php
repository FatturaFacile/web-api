<?php

declare(strict_types=1);

namespace Modules\Associations\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request and response payloads of the books, the protocol, the archive, volunteers, shifts and reimbursements.
 */
#[OA\Schema(schema: 'AssociationBook', properties: [
    new OA\Property(property: 'book', type: 'string', enum: ['members', 'volunteers', 'meetings', 'protocol']),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'year', type: 'integer', nullable: true),
    new OA\Property(property: 'headers', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'rows', type: 'array', items: new OA\Items(type: 'array', items: new OA\Items(type: 'string', nullable: true))),
], type: 'object')]
#[OA\Schema(schema: 'AssociationArchiveResults', type: 'array', items: new OA\Items(properties: [
    new OA\Property(property: 'type', type: 'string', enum: ['meeting', 'minutes', 'protocol']),
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'reference', type: 'string', nullable: true),
], type: 'object'))]
#[OA\Schema(schema: 'AssociationProtocolEntryRequest', required: ['direction', 'correspondent', 'subject'], properties: [
    new OA\Property(property: 'direction', type: 'string', enum: ['incoming', 'outgoing', 'internal']),
    new OA\Property(property: 'registered_on', type: 'string', format: 'date'),
    new OA\Property(property: 'document_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'correspondent', type: 'string'),
    new OA\Property(property: 'subject', type: 'string'),
    new OA\Property(property: 'category', type: 'string', nullable: true),
    new OA\Property(property: 'meeting_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'file', type: 'string', format: 'binary'),
], type: 'object')]
#[OA\Schema(schema: 'AssociationRequiredReasonRequest', required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string')], type: 'object')]
#[OA\Schema(schema: 'AssociationVolunteerExpiries', properties: [
    new OA\Property(property: 'trainings', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssociationRecord')),
    new OA\Property(property: 'insurance', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssociationRecord')),
], type: 'object')]
#[OA\Schema(schema: 'AssociationShiftVolunteerRequest', required: ['volunteer_id'], properties: [
    new OA\Property(property: 'volunteer_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'is_available', type: 'boolean'),
], type: 'object')]
#[OA\Schema(schema: 'AssociationShiftCoverage', properties: [
    new OA\Property(property: 'shift_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'required', type: 'integer'),
    new OA\Property(property: 'covered', type: 'integer'),
    new OA\Property(property: 'missing', type: 'integer'),
    new OA\Property(property: 'substitutions_requested', type: 'integer'),
    new OA\Property(property: 'available_volunteer_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
], type: 'object')]
#[OA\Schema(schema: 'AssociationReimbursementRequest', required: ['member_id', 'kind', 'incurred_on', 'description'], properties: [
    new OA\Property(property: 'member_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'volunteer_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'kind', type: 'string', enum: ['mileage', 'expense']),
    new OA\Property(property: 'incurred_on', type: 'string', format: 'date'),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'vehicle', description: 'Mileage only', type: 'string', nullable: true),
    new OA\Property(property: 'plate', description: 'Mileage only; stored encrypted', type: 'string', nullable: true),
    new OA\Property(property: 'distance_km', description: 'Mileage only', type: 'string', example: '42.50', nullable: true),
    new OA\Property(property: 'unit_price', description: 'Mileage only: the price per km the association applies', type: 'string', example: '0.3000', nullable: true),
    new OA\Property(property: 'amount', description: 'Expense only; a journey is calculated', type: 'string', example: '18.40', nullable: true),
    new OA\Property(property: 'project_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'campaign_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'expense_account_id', type: 'string', format: 'uuid', nullable: true),
], type: 'object')]
#[OA\Schema(schema: 'AssociationReimbursementDecisionRequest', required: ['approved'], properties: [
    new OA\Property(property: 'approved', type: 'boolean'),
    new OA\Property(property: 'reason', description: 'Required for a rejection', type: 'string', nullable: true),
], type: 'object')]
#[OA\Schema(schema: 'AssociationPaymentDetailsRequest', required: ['paid_on', 'payment_method'], properties: [
    new OA\Property(property: 'paid_on', type: 'string', format: 'date'),
    new OA\Property(property: 'payment_method', type: 'string', enum: ['cash', 'bank']),
], type: 'object')]
final class BooksAndVolunteersOpenApiSchemas {}
