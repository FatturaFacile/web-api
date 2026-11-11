<?php

declare(strict_types=1);

namespace Modules\Quality\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses, payloads and records of the quality management endpoints.
 */
#[OA\Tag(name: 'Quality', description: 'Quality management system: non-conformities from any source, corrective and preventive actions (CAPA) with effectiveness check, internal audits with checklists and findings, document-controlled procedures approved through the Workflow module, indicators')]
#[OA\Response(response: 'QualityUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'QualityForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'QualityNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'QualityUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'QmsNonconformityRequest', required: ['title', 'source'], type: 'object', properties: [
    new OA\Property(property: 'title', type: 'string', maxLength: 190),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'source', type: 'string', enum: ['supplier', 'customer_complaint', 'internal', 'audit', 'manufacturing']),
    new OA\Property(property: 'severity', type: 'string', enum: ['minor', 'major', 'critical'], nullable: true, description: 'Defaults to minor'),
    new OA\Property(property: 'detected_at', type: 'string', format: 'date', nullable: true, description: 'Defaults to today'),
    new OA\Property(property: 'owner_id', description: 'User of the company', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'contact_id', description: 'Supplier or customer (contact of the company), by id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'mfg_non_conformance_id', description: 'Manufacturing non-conformance of the company, by id; only for the source manufacturing. It is never changed from here', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'containment_action', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'QmsNonconformityUpdateRequest', type: 'object', properties: [
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'severity', type: 'string', enum: ['minor', 'major', 'critical']),
    new OA\Property(property: 'owner_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'containment_action', type: 'string', nullable: true),
    new OA\Property(property: 'root_cause', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'QmsInvestigateRequest', type: 'object', properties: [new OA\Property(property: 'owner_id', type: 'string', format: 'uuid', nullable: true)])]
#[OA\Schema(schema: 'QmsCloseRequest', type: 'object', properties: [new OA\Property(property: 'closure_notes', type: 'string', nullable: true)])]
#[OA\Schema(schema: 'QmsCancelRequest', required: ['reason'], type: 'object', properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 2000)])]
#[OA\Schema(schema: 'QmsNonconformity', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'number', type: 'string', example: 'NC-2027-0001'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'source', type: 'string', enum: ['supplier', 'customer_complaint', 'internal', 'audit', 'manufacturing']),
    new OA\Property(property: 'severity', type: 'string', enum: ['minor', 'major', 'critical']),
    new OA\Property(property: 'status', type: 'string', enum: ['open', 'investigating', 'capa_in_progress', 'closed', 'cancelled']),
    new OA\Property(property: 'detected_at', type: 'string', format: 'date'),
    new OA\Property(property: 'detected_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'owner_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'mfg_non_conformance_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'audit_finding_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'containment_action', type: 'string', nullable: true),
    new OA\Property(property: 'root_cause', type: 'string', nullable: true),
    new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'closed_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'closure_notes', type: 'string', nullable: true),
    new OA\Property(property: 'capa_actions', description: 'Only on the single record', type: 'array', items: new OA\Items(ref: '#/components/schemas/QmsCapaAction')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'QmsCapaRequest', required: ['title', 'owner_id', 'due_date'], type: 'object', properties: [
    new OA\Property(property: 'nonconformity_id', description: 'Open non-conformity of the company; omit for a standalone (preventive) action', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'type', type: 'string', enum: ['corrective', 'preventive'], nullable: true),
    new OA\Property(property: 'title', type: 'string', maxLength: 190),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'owner_id', description: 'User of the company who is responsible', type: 'string', format: 'uuid'),
    new OA\Property(property: 'due_date', type: 'string', format: 'date'),
    new OA\Property(property: 'effectiveness_check_due_date', description: 'Not before the due date', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'QmsCapaUpdateRequest', type: 'object', properties: [
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'owner_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'due_date', type: 'string', format: 'date'),
    new OA\Property(property: 'effectiveness_check_due_date', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'QmsCapaCompleteRequest', type: 'object', properties: [new OA\Property(property: 'effectiveness_check_due_date', type: 'string', format: 'date', nullable: true)])]
#[OA\Schema(schema: 'QmsCapaVerifyRequest', required: ['result'], type: 'object', properties: [
    new OA\Property(property: 'result', type: 'string', enum: ['effective', 'not_effective']),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'QmsCapaAction', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'nonconformity_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'number', type: 'string', example: 'CAPA-2027-0001'),
    new OA\Property(property: 'type', type: 'string', enum: ['corrective', 'preventive']),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'owner_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'due_date', type: 'string', format: 'date'),
    new OA\Property(property: 'status', type: 'string', enum: ['open', 'in_progress', 'completed', 'verified', 'ineffective', 'cancelled']),
    new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'completed_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'effectiveness_check_due_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'effectiveness_checked_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'effectiveness_checked_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'effectiveness_result', type: 'string', enum: ['effective', 'not_effective'], nullable: true),
    new OA\Property(property: 'effectiveness_notes', type: 'string', nullable: true),
    new OA\Property(property: 'is_overdue', description: 'Open or in progress past the due date', type: 'boolean'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'QmsAuditRequest', required: ['title', 'planned_date'], type: 'object', properties: [
    new OA\Property(property: 'title', type: 'string', maxLength: 190),
    new OA\Property(property: 'scope', type: 'string', nullable: true),
    new OA\Property(property: 'audit_type', type: 'string', enum: ['internal', 'process', 'supplier'], nullable: true),
    new OA\Property(property: 'planned_date', type: 'string', format: 'date'),
    new OA\Property(property: 'lead_auditor_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'auditee', type: 'string', nullable: true),
    new OA\Property(property: 'checklist', type: 'array', items: new OA\Items(type: 'object', required: ['requirement'], properties: [
        new OA\Property(property: 'clause', type: 'string', nullable: true),
        new OA\Property(property: 'requirement', type: 'string'),
    ])),
])]
#[OA\Schema(schema: 'QmsAuditUpdateRequest', type: 'object', properties: [
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'scope', type: 'string', nullable: true),
    new OA\Property(property: 'audit_type', type: 'string', enum: ['internal', 'process', 'supplier']),
    new OA\Property(property: 'planned_date', type: 'string', format: 'date'),
    new OA\Property(property: 'lead_auditor_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'auditee', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'QmsAuditCompleteRequest', type: 'object', properties: [new OA\Property(property: 'summary', type: 'string', nullable: true)])]
#[OA\Schema(schema: 'QmsChecklistResultRequest', required: ['result'], type: 'object', properties: [
    new OA\Property(property: 'result', type: 'string', enum: ['pending', 'conforming', 'nonconforming', 'observation', 'not_applicable']),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'QmsFindingRequest', required: ['classification', 'description'], type: 'object', properties: [
    new OA\Property(property: 'classification', type: 'string', enum: ['major_nc', 'minor_nc', 'observation', 'opportunity']),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'checklist_item_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'owner_id', description: 'Owner of the non-conformity raised by a major or minor finding', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'QmsAuditChecklistItem', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'audit_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'position', type: 'integer'),
    new OA\Property(property: 'clause', type: 'string', nullable: true),
    new OA\Property(property: 'requirement', type: 'string'),
    new OA\Property(property: 'result', type: 'string', enum: ['pending', 'conforming', 'nonconforming', 'observation', 'not_applicable']),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'QmsAuditFinding', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'audit_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'checklist_item_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'number', type: 'string', example: 'FND-2027-0001'),
    new OA\Property(property: 'classification', type: 'string', enum: ['major_nc', 'minor_nc', 'observation', 'opportunity']),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'nonconformity_id', description: 'Non-conformity raised by a major or minor finding', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['open', 'closed']),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'QmsAudit', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'number', type: 'string', example: 'AUD-2027-0001'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'scope', type: 'string', nullable: true),
    new OA\Property(property: 'audit_type', type: 'string', enum: ['internal', 'process', 'supplier']),
    new OA\Property(property: 'planned_date', type: 'string', format: 'date'),
    new OA\Property(property: 'performed_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'lead_auditor_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'auditee', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['planned', 'in_progress', 'completed', 'cancelled']),
    new OA\Property(property: 'summary', type: 'string', nullable: true),
    new OA\Property(property: 'checklist', type: 'array', items: new OA\Items(ref: '#/components/schemas/QmsAuditChecklistItem')),
    new OA\Property(property: 'findings', type: 'array', items: new OA\Items(ref: '#/components/schemas/QmsAuditFinding')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'QmsProcedureRequest', required: ['code', 'title'], type: 'object', properties: [
    new OA\Property(property: 'code', type: 'string', maxLength: 40, example: 'PRO-001'),
    new OA\Property(property: 'title', type: 'string', maxLength: 190),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'document_id', description: 'Document of the Documents module of the company, referenced by id only', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'owner_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'review_due_date', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'QmsProcedureUpdateRequest', type: 'object', properties: [
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'document_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'owner_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'review_due_date', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'QmsProcedure', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'version', type: 'integer'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'document_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'owner_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'author_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'pending_approval', 'approved', 'rejected', 'obsolete']),
    new OA\Property(property: 'effective_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'review_due_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'approved_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'approved_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'rejected_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'rejected_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'rejection_reason', type: 'string', nullable: true),
    new OA\Property(property: 'workflow_instance_id', description: 'Approval workflow instance, when the company has the definition quality_procedure_approval', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'QmsKpis', type: 'object', properties: [
    new OA\Property(property: 'as_of', type: 'string', format: 'date'),
    new OA\Property(property: 'open_nonconformities', type: 'object', properties: [
        new OA\Property(property: 'total', type: 'integer'),
        new OA\Property(property: 'by_age', description: 'Buckets 0-30, 31-60, 61-90, 90+ days from the detection date', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
        new OA\Property(property: 'by_source', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
        new OA\Property(property: 'by_severity', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
        new OA\Property(property: 'oldest_days', type: 'integer', nullable: true),
    ]),
    new OA\Property(property: 'capa', type: 'object', properties: [
        new OA\Property(property: 'total', type: 'integer'),
        new OA\Property(property: 'open', type: 'integer'),
        new OA\Property(property: 'overdue', type: 'integer'),
        new OA\Property(property: 'completed', type: 'integer'),
        new OA\Property(property: 'completed_on_time', type: 'integer'),
        new OA\Property(property: 'completed_late', type: 'integer'),
        new OA\Property(property: 'on_time_rate', description: 'Percent: completed on time over completed plus overdue; null when nothing is measurable', type: 'number', nullable: true),
        new OA\Property(property: 'ineffective', type: 'integer'),
    ]),
    new OA\Property(property: 'audits', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
    new OA\Property(property: 'procedures', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
])]
final class QualityOpenApi {}
