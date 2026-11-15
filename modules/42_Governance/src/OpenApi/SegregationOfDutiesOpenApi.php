<?php

declare(strict_types=1);

namespace Modules\Governance\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Payloads and records of the segregation of duties endpoints.
 */
#[OA\Schema(schema: 'GovSodRuleRequest', required: ['code', 'title', 'duties_a', 'duties_b'], type: 'object', properties: [
    new OA\Property(property: 'code', description: 'Set on creation only', type: 'string', maxLength: 30),
    new OA\Property(property: 'title', type: 'string', maxLength: 190),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'duties_a', description: 'First group: holding any of these conflicts with any duty of the second group. A duty is a permission such as treasury.write or gl.*', type: 'array', items: new OA\Items(type: 'string', maxLength: 120)),
    new OA\Property(property: 'duties_b', description: 'Second group of conflicting duties', type: 'array', items: new OA\Items(type: 'string', maxLength: 120)),
    new OA\Property(property: 'severity', description: 'warn records a violation, block refuses the assignment unless an exception is active', type: 'string', enum: ['warn', 'block']),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'GovSodRule', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'duties_a', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'duties_b', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'severity', type: 'string', enum: ['warn', 'block']),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'GovSodViolation', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'rule_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'rule_code', type: 'string'),
    new OA\Property(property: 'rule_title', type: 'string'),
    new OA\Property(property: 'severity', type: 'string', enum: ['warn', 'block']),
    new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'user', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'name', type: 'string')], type: 'object', nullable: true),
    new OA\Property(property: 'duty_a', type: 'string'),
    new OA\Property(property: 'duty_b', type: 'string'),
    new OA\Property(property: 'status', type: 'string', enum: ['open', 'excepted', 'resolved']),
    new OA\Property(property: 'exception_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'detected_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'resolved_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'GovSodExceptionRequest', required: ['rule_id', 'user_id', 'justification', 'expires_at'], type: 'object', properties: [
    new OA\Property(property: 'rule_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'user_id', description: 'A member of the company; the approver cannot be the same person', type: 'string', format: 'uuid'),
    new OA\Property(property: 'justification', type: 'string', minLength: 10, maxLength: 4000),
    new OA\Property(property: 'expires_at', description: 'Y-m-d, after today and within one year', type: 'string', format: 'date'),
])]
#[OA\Schema(schema: 'GovSodException', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'rule_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'rule_code', type: 'string'),
    new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'justification', type: 'string'),
    new OA\Property(property: 'approved_by', type: 'string', format: 'uuid'),
    new OA\Property(property: 'expires_at', type: 'string', format: 'date'),
    new OA\Property(property: 'status', type: 'string', enum: ['active', 'expired', 'revoked']),
    new OA\Property(property: 'revoked_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'GovSodCheckRequest', required: ['role'], type: 'object', properties: [
    new OA\Property(property: 'role', type: 'string', maxLength: 50),
    new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string', maxLength: 120)),
])]
#[OA\Schema(schema: 'GovSodDocumentRuleRequest', required: ['severity', 'is_active'], type: 'object', properties: [
    new OA\Property(property: 'severity', description: 'block refuses the approval or release by anyone who prepared the document, warn lets it through and writes it on the audit ledger', type: 'string', enum: ['warn', 'block']),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'GovSodDocumentRule', type: 'object', properties: [
    new OA\Property(property: 'document_type', type: 'string', enum: ['payment', 'purchase_order', 'journal', 'payroll_run']),
    new OA\Property(property: 'severity', type: 'string', enum: ['warn', 'block']),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'configured', description: 'False while the company has not set the rule', type: 'boolean'),
])]
final class SegregationOfDutiesOpenApi {}
