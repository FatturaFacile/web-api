<?php

declare(strict_types=1);

namespace Modules\Childcare\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Parameters, payloads and responses shared by the childcare endpoints.
 */
#[OA\Tag(name: 'Childcare', description: 'Kindergartens: children, attendance, care, documents, fees and cantonal subsidies')]
#[OA\Tag(name: 'Childcare Family Portal', description: 'What guardians see and do with their personal link')]
#[OA\SecurityScheme(securityScheme: 'familyToken', type: 'apiKey', description: 'Personal token of a guardian on the family portal', name: 'X-Family-Token', in: 'header')]
#[OA\Response(response: 'ChildcareUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'ChildcareForbidden', description: 'The role of the user does not allow this area of the module', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'ChildcareNotFound', description: 'Record not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'ChildcareUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'ChildcareGroupRequest', type: 'object', required: ['name'], properties: [
    new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'capacity', type: 'integer'),
])]
#[OA\Schema(schema: 'ChildcareChildRequest', type: 'object', required: ['billing_contact_id', 'first_name', 'last_name', 'enrolled_on'], properties: [
    new OA\Property(property: 'group_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'billing_contact_id', description: 'Contact the fees are invoiced to', type: 'string', format: 'uuid'),
    new OA\Property(property: 'first_name', type: 'string'),
    new OA\Property(property: 'last_name', type: 'string'),
    new OA\Property(property: 'birth_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'allergies', type: 'string', nullable: true),
    new OA\Property(property: 'dietary_notes', type: 'string', nullable: true),
    new OA\Property(property: 'health_notes', type: 'string', nullable: true),
    new OA\Property(property: 'subsidy_canton', type: 'string', example: 'TI', nullable: true),
    new OA\Property(property: 'subsidy_inputs', description: 'Numeric inputs named by the subsidy rule of the canton, such as taxable_income', type: 'object', nullable: true),
    new OA\Property(property: 'enrolled_on', type: 'string', format: 'date'),
    new OA\Property(property: 'left_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['active', 'left']),
])]
#[OA\Schema(schema: 'ChildcareAdmitRequest', type: 'object', properties: [
    new OA\Property(property: 'group_id', description: 'Group to admit into; defaults to the group of the child', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'enrolled_on', description: 'Defaults to today of the company', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'ChildcareReferencePersonRequest', type: 'object', required: ['full_name', 'relationship', 'is_guardian', 'can_pick_up'], properties: [
    new OA\Property(property: 'full_name', type: 'string'),
    new OA\Property(property: 'phone', type: 'string', nullable: true),
    new OA\Property(property: 'email', type: 'string', nullable: true),
    new OA\Property(property: 'relationship', type: 'string', enum: ['mother', 'father', 'guardian', 'grandparent', 'relative', 'other']),
    new OA\Property(property: 'is_guardian', type: 'boolean'),
    new OA\Property(property: 'can_pick_up', type: 'boolean'),
    new OA\Property(property: 'portal_contact_id', description: 'Contact of the guardian on the family portal', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'ChildcareWeeklyPlanRequest', type: 'object', required: ['valid_from', 'days'], properties: [
    new OA\Property(property: 'valid_from', type: 'string', format: 'date'),
    new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'days', description: 'Keys mon..sun', type: 'object', example: ['mon' => ['from' => '08:00', 'to' => '17:00', 'meals' => ['lunch', 'snack']]]),
])]
#[OA\Schema(schema: 'ChildcareChildFeeRequest', type: 'object', required: ['fee_plan_id', 'valid_from'], properties: [
    new OA\Property(property: 'fee_plan_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'valid_from', type: 'string', format: 'date'),
    new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'ChildcareCheckInRequest', type: 'object', required: ['child_id'], properties: [
    new OA\Property(property: 'child_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'at', description: 'Defaults to now', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'temperature', type: 'string', example: '36.8', nullable: true),
])]
#[OA\Schema(schema: 'ChildcareCheckOutRequest', type: 'object', required: ['child_id', 'picked_up_by'], properties: [
    new OA\Property(property: 'child_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'picked_up_by', description: 'Reference person authorised to pick up', type: 'string', format: 'uuid'),
])]
#[OA\Schema(schema: 'ChildcareAbsenceRequest', type: 'object', required: ['child_id', 'date', 'reason'], properties: [
    new OA\Property(property: 'child_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'date', type: 'string', format: 'date'),
    new OA\Property(property: 'reason', type: 'string', enum: ['sick', 'holiday', 'other']),
])]
#[OA\Schema(schema: 'ChildcareCorrectionRequest', type: 'object', required: ['reason'], properties: [
    new OA\Property(property: 'reason', type: 'string'),
    new OA\Property(property: 'status', type: 'string', enum: ['present', 'absent']),
    new OA\Property(property: 'absence_reason', type: 'string', nullable: true),
    new OA\Property(property: 'check_in_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'check_out_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'temperature', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'ChildcareNoteRequest', type: 'object', required: ['child_id', 'date', 'category', 'body'], properties: [
    new OA\Property(property: 'child_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'date', type: 'string', format: 'date'),
    new OA\Property(property: 'category', type: 'string', enum: ['general', 'meal', 'sleep', 'activity', 'health', 'behaviour']),
    new OA\Property(property: 'body', type: 'string'),
    new OA\Property(property: 'visible_to_family', type: 'boolean'),
])]
#[OA\Schema(schema: 'ChildcareStaffShiftRequest', type: 'object', required: ['user_id', 'date', 'starts_at', 'ends_at'], properties: [
    new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'group_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'date', type: 'string', format: 'date'),
    new OA\Property(property: 'starts_at', type: 'string', example: '07:30'),
    new OA\Property(property: 'ends_at', type: 'string', example: '16:00'),
    new OA\Property(property: 'break_minutes', type: 'integer'),
])]
#[OA\Schema(schema: 'ChildcareActivityRequest', type: 'object', required: ['name', 'weekday', 'starts_at', 'ends_at'], properties: [
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'weekday', description: '1 Monday .. 7 Sunday', type: 'integer'),
    new OA\Property(property: 'starts_at', type: 'string'),
    new OA\Property(property: 'ends_at', type: 'string'),
    new OA\Property(property: 'capacity', type: 'integer'),
    new OA\Property(property: 'monthly_fee', type: 'string'),
    new OA\Property(property: 'tax_rate', type: 'string'),
])]
#[OA\Schema(schema: 'ChildcareEnrolmentRequest', type: 'object', required: ['child_id', 'valid_from'], properties: [
    new OA\Property(property: 'child_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'valid_from', type: 'string', format: 'date'),
    new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'ChildcareMessageRequest', type: 'object', required: ['body'], properties: [new OA\Property(property: 'body', type: 'string')])]
#[OA\Schema(schema: 'ChildcareFamilyMessageRequest', type: 'object', required: ['child_id', 'body'], properties: [
    new OA\Property(property: 'child_id', type: 'string', format: 'uuid'), new OA\Property(property: 'body', type: 'string'),
])]
#[OA\Schema(schema: 'ChildcareSignRequest', type: 'object', required: ['signed_name', 'accept'], properties: [
    new OA\Property(property: 'signed_name', type: 'string'), new OA\Property(property: 'accept', type: 'boolean'),
])]
#[OA\Schema(schema: 'ChildcareFeePlanRequest', type: 'object', required: ['name', 'basis', 'amount', 'tax_rate', 'currency'], properties: [
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'basis', type: 'string', enum: ['monthly_flat', 'per_day']),
    new OA\Property(property: 'amount', type: 'string'),
    new OA\Property(property: 'meal_amount', type: 'string'),
    new OA\Property(property: 'tax_rate', description: 'Entered by the kindergarten', type: 'string'),
    new OA\Property(property: 'currency', type: 'string'),
])]
#[OA\Schema(schema: 'ChildcareSubsidyRuleRequest', type: 'object', required: ['canton', 'name', 'method', 'parameters', 'valid_from'], properties: [
    new OA\Property(property: 'canton', type: 'string', example: 'TI'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'method', type: 'string', enum: ['fixed_per_day', 'income_brackets_per_day', 'income_brackets_percentage']),
    new OA\Property(property: 'parameters', description: 'fixed_per_day: {amount_per_day}; income_brackets_*: {input, brackets: [{up_to|null, amount_per_day|percentage}]}; optional monthly_cap', type: 'object'),
    new OA\Property(property: 'valid_from', type: 'string', format: 'date'),
    new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'source_reference', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'ChildcareVerifyRuleRequest', type: 'object', required: ['source_reference'], properties: [
    new OA\Property(property: 'source_reference', description: 'Official source the parameters were checked against', type: 'string'),
])]
#[OA\Schema(schema: 'ChildcareBillingRunRequest', type: 'object', required: ['period', 'date'], properties: [
    new OA\Property(property: 'period', type: 'string', example: '2026-11'), new OA\Property(property: 'date', description: 'Invoice date', type: 'string', format: 'date'),
])]
#[OA\Schema(schema: 'ChildcareRequirementRequest', type: 'object', required: ['name'], properties: [
    new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'requires_signature', type: 'boolean'), new OA\Property(property: 'is_mandatory', type: 'boolean'),
])]
#[OA\Schema(schema: 'ChildcareDocumentRequest', type: 'object', required: ['title'], properties: [
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'requirement_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'file', description: 'PDF, JPG or PNG up to 10 MB', type: 'string', format: 'binary'),
])]
#[OA\Schema(schema: 'ChildcareRecordResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid')]),
])]
#[OA\Schema(schema: 'ChildcareRecordCollection', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])]
#[OA\Schema(schema: 'ChildcareDataResponse', type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])]
#[OA\Schema(schema: 'ChildcareMessageResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'message', type: 'string')]),
])]
final class ChildcareOpenApi {}
