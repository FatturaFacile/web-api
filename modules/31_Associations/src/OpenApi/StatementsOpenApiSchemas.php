<?php

declare(strict_types=1);

namespace Modules\Associations\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request and response payloads of the third-sector statements.
 */
#[OA\Schema(schema: 'AssociationStatementSchemes', properties: [
    new OA\Property(property: 'source', type: 'string'),
    new OA\Property(property: 'A', description: 'Balance sheet lines by code', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
    new OA\Property(property: 'B', description: 'Management report lines by code', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
    new OA\Property(property: 'D', description: 'Cash report lines by code', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
    new OA\Property(property: 'C', description: 'Points of the mission report', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
], type: 'object')]
#[OA\Schema(schema: 'AssociationStatementMappingRequest', required: ['scheme', 'line_code', 'account_id'], properties: [
    new OA\Property(property: 'scheme', type: 'string', enum: ['A', 'B', 'D']),
    new OA\Property(property: 'line_code', type: 'string', example: 'B-R.A.1'),
    new OA\Property(property: 'account_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'cost_center_id', description: 'Not for the balance sheet', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'is_commercial', type: 'boolean'),
], type: 'object')]
#[OA\Schema(schema: 'AssociationStatement', properties: [
    new OA\Property(property: 'year', type: 'integer'),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'label', type: 'string'),
        new OA\Property(property: 'section', type: 'string'),
        new OA\Property(property: 'side', type: 'string'),
        new OA\Property(property: 'current', type: 'string'),
        new OA\Property(property: 'previous', type: 'string'),
    ], type: 'object')),
    new OA\Property(property: 'sections', type: 'object', additionalProperties: true),
    new OA\Property(property: 'totals', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
    new OA\Property(property: 'unmapped', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true)),
], type: 'object')]
#[OA\Schema(schema: 'AssociationStatementChecks', type: 'array', items: new OA\Items(properties: [
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'severity', type: 'string', enum: ['error', 'warning', 'info']),
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'details', type: 'object', additionalProperties: true),
], type: 'object'))]
#[OA\Schema(schema: 'AssociationMissionReport', type: 'array', items: new OA\Items(properties: [
    new OA\Property(property: 'point', type: 'integer'),
    new OA\Property(property: 'heading', type: 'string'),
    new OA\Property(property: 'facts', type: 'object', additionalProperties: true),
    new OA\Property(property: 'text', type: 'string', nullable: true),
], type: 'object'))]
#[OA\Schema(schema: 'AssociationMissionReportRequest', required: ['answers'], properties: [
    new OA\Property(property: 'answers', description: 'Texts by point number, 1 to 24', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string', nullable: true)),
], type: 'object')]
#[OA\Schema(schema: 'AssociationPrepareFilingRequest', required: ['form'], properties: [
    new OA\Property(property: 'form', type: 'string', enum: ['ordinary', 'cash']),
], type: 'object')]
final class StatementsOpenApiSchemas {}
