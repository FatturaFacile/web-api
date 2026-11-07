<?php

declare(strict_types=1);

namespace Modules\Associations\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request and response payloads of the data protection endpoints. Generated texts come with a meta block that marks
 * them as templates to be validated by a professional.
 */
#[OA\Schema(schema: 'AssociationTemplateMeta', properties: [
    new OA\Property(property: 'template', type: 'boolean', example: true),
    new OA\Property(property: 'requires_professional_validation', type: 'boolean', example: true),
    new OA\Property(property: 'notice', type: 'string'),
], type: 'object')]
#[OA\Schema(schema: 'AssociationProcessingActivityRequest', properties: [
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'data_subjects', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'data_categories', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'special_categories', type: 'boolean'),
    new OA\Property(property: 'purposes', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'legal_bases', type: 'array', items: new OA\Items(type: 'string', enum: ['consent', 'contract', 'legal_obligation', 'vital_interests', 'public_task', 'legitimate_interests'])),
    new OA\Property(property: 'collection_methods', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'storage', type: 'string', nullable: true),
    new OA\Property(property: 'retention', type: 'string'),
    new OA\Property(property: 'recipients', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'transfers_outside_eu', type: 'string', nullable: true),
    new OA\Property(property: 'security_measures', type: 'string', nullable: true),
    new OA\Property(property: 'end_of_processing', type: 'string', nullable: true),
], type: 'object')]
#[OA\Schema(
    schema: 'AssociationProcessingActivity',
    allOf: [new OA\Schema(ref: '#/components/schemas/AssociationRecord'), new OA\Schema(properties: [
        new OA\Property(property: 'family_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'version', type: 'integer'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'final', 'superseded']),
        new OA\Property(property: 'name', type: 'string'),
    ], type: 'object')],
)]
#[OA\Schema(schema: 'AssociationProcessingRegister', properties: [
    new OA\Property(property: 'controller', type: 'object', additionalProperties: true),
    new OA\Property(property: 'dpo', type: 'object', nullable: true, additionalProperties: true),
    new OA\Property(property: 'headers', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'rows', type: 'array', items: new OA\Items(type: 'array', items: new OA\Items(type: 'string'))),
], type: 'object')]
#[OA\Schema(schema: 'AssociationGeneratedDocument', properties: [
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'sections', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'heading', type: 'string'),
        new OA\Property(property: 'text', type: 'string'),
    ], type: 'object')),
], type: 'object')]
#[OA\Schema(schema: 'AssociationConsentRequest', required: ['activity_id', 'purpose', 'method', 'text_version', 'proof'], properties: [
    new OA\Property(property: 'member_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'activity_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'purpose', type: 'string'),
    new OA\Property(property: 'method', type: 'string', enum: ['online_form', 'paper_form', 'email', 'member_area', 'other']),
    new OA\Property(property: 'text_version', type: 'string'),
    new OA\Property(property: 'proof', description: 'How the consent was given: form reference, signed document, message; stored encrypted with its hash', type: 'string'),
    new OA\Property(property: 'given_at', type: 'string', format: 'date-time'),
], type: 'object')]
#[OA\Schema(schema: 'AssociationDataSubjectRequest', required: ['kind', 'requester_name'], properties: [
    new OA\Property(property: 'kind', type: 'string', enum: ['access', 'rectification', 'erasure', 'portability', 'restriction', 'objection']),
    new OA\Property(property: 'member_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'requester_name', type: 'string'),
    new OA\Property(property: 'requester_contact', type: 'string', nullable: true),
    new OA\Property(property: 'details', type: 'string', nullable: true),
    new OA\Property(property: 'received_at', type: 'string', format: 'date-time'),
], type: 'object')]
#[OA\Schema(schema: 'AssociationOutcomeRequest', required: ['outcome'], properties: [new OA\Property(property: 'outcome', type: 'string')], type: 'object')]
#[OA\Schema(schema: 'AssociationErasurePreview', properties: [
    new OA\Property(property: 'kept', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'record', type: 'string'),
        new OA\Property(property: 'count', type: 'integer'),
        new OA\Property(property: 'reason', type: 'string', enum: ['legal_retention', 'retention_period_not_configured', 'still_in_use']),
        new OA\Property(property: 'until', type: 'string', format: 'date', nullable: true),
    ], type: 'object')),
], type: 'object')]
final class PrivacyOpenApiSchemas {}
