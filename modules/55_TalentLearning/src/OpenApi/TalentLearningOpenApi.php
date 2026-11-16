<?php

declare(strict_types=1);

namespace Modules\TalentLearning\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses, payloads and records of the talent and learning endpoints.
 */
#[OA\Tag(name: 'Talent and learning', description: 'Succession planning, compensation planning cycles with merit proposals, learning catalogue with paths, enrolments, certificates and mandatory training compliance, engagement surveys with anonymity threshold and eNPS')]
#[OA\Response(response: 'TalentUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'TalentForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'TalentNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'TalentUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'TalentEmployeeSummary', type: 'object', nullable: true, properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'full_name', type: 'string'),
    new OA\Property(property: 'employee_number', type: 'string', nullable: true),
    new OA\Property(property: 'position_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'position_title', type: 'string', nullable: true),
    new OA\Property(property: 'department_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'department_name', type: 'string', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'TalentEmployeeLookup', ref: '#/components/schemas/TalentEmployeeSummary')]
#[OA\Schema(schema: 'TalentPositionLookup', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'department_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'department_name', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'TalentSuccessionPosition', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'position_id', description: 'Position of human resources', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'incumbent_employee_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'incumbent', ref: '#/components/schemas/TalentEmployeeSummary'),
    new OA\Property(property: 'criticality', type: 'string', enum: ['low', 'medium', 'high', 'critical']),
    new OA\Property(property: 'vacancy_risk', type: 'string', enum: ['low', 'medium', 'high']),
    new OA\Property(property: 'status', type: 'string', enum: ['active', 'archived']),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'coverage', description: 'covered: somebody is ready now and not at high risk of loss; at_risk: the only ones ready now are at high risk of loss; uncovered: nobody is ready now', type: 'string', enum: ['covered', 'at_risk', 'uncovered']),
    new OA\Property(property: 'candidates', type: 'array', items: new OA\Items(ref: '#/components/schemas/TalentSuccessorCandidate')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'TalentSuccessionPositionRequest', type: 'object', properties: [
    new OA\Property(property: 'position_id', description: 'Position of human resources; the title is copied from it when omitted', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'title', description: 'Required without position_id', type: 'string', maxLength: 150, nullable: true),
    new OA\Property(property: 'incumbent_employee_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'criticality', type: 'string', enum: ['low', 'medium', 'high', 'critical']),
    new OA\Property(property: 'vacancy_risk', type: 'string', enum: ['low', 'medium', 'high']),
    new OA\Property(property: 'status', type: 'string', enum: ['active', 'archived']),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'TalentSuccessorCandidate', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'succession_position_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'employee', ref: '#/components/schemas/TalentEmployeeSummary'),
    new OA\Property(property: 'readiness', type: 'string', enum: ['ready_now', 'ready_1_2_years', 'ready_3_plus_years']),
    new OA\Property(property: 'risk_of_loss', type: 'string', enum: ['low', 'medium', 'high']),
    new OA\Property(property: 'potential', type: 'string', enum: ['low', 'medium', 'high']),
    new OA\Property(property: 'ranking', type: 'integer'),
    new OA\Property(property: 'development_plan', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'TalentSuccessorCandidateRequest', type: 'object', required: ['employee_id'], properties: [
    new OA\Property(property: 'employee_id', description: 'Required on creation, fixed afterwards', type: 'string', format: 'uuid'),
    new OA\Property(property: 'readiness', type: 'string', enum: ['ready_now', 'ready_1_2_years', 'ready_3_plus_years']),
    new OA\Property(property: 'risk_of_loss', type: 'string', enum: ['low', 'medium', 'high']),
    new OA\Property(property: 'potential', type: 'string', enum: ['low', 'medium', 'high']),
    new OA\Property(property: 'ranking', type: 'integer', minimum: 1),
    new OA\Property(property: 'development_plan', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'TalentPlanningDashboard', type: 'object', properties: [
    new OA\Property(property: 'succession', type: 'object', properties: [
        new OA\Property(property: 'positions_total', type: 'integer'),
        new OA\Property(property: 'coverage', type: 'object', properties: [new OA\Property(property: 'covered', type: 'integer'), new OA\Property(property: 'at_risk', type: 'integer'), new OA\Property(property: 'uncovered', type: 'integer')]),
        new OA\Property(property: 'candidates_by_readiness', type: 'object', properties: [new OA\Property(property: 'ready_now', type: 'integer'), new OA\Property(property: 'ready_1_2_years', type: 'integer'), new OA\Property(property: 'ready_3_plus_years', type: 'integer')]),
        new OA\Property(property: 'high_risk_of_loss_candidates', type: 'integer'),
        new OA\Property(property: 'positions_needing_attention', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'title', type: 'string'), new OA\Property(property: 'criticality', type: 'string'), new OA\Property(property: 'vacancy_risk', type: 'string'), new OA\Property(property: 'coverage', type: 'string')])),
    ]),
    new OA\Property(property: 'compensation', type: 'object', properties: [
        new OA\Property(property: 'cycles_total', type: 'integer'),
        new OA\Property(property: 'open_cycles', type: 'integer'),
        new OA\Property(property: 'current_cycle', description: 'The open cycle, or the latest one; the fields of TalentCompCycleSummary plus id, name and status', type: 'object', nullable: true),
    ]),
])]
#[OA\Schema(schema: 'TalentCompCycle', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'fiscal_year', type: 'integer'),
    new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
    new OA\Property(property: 'starts_on', type: 'string', format: 'date'),
    new OA\Property(property: 'ends_on', type: 'string', format: 'date'),
    new OA\Property(property: 'effective_on', description: 'Date the approved increases take effect', type: 'string', format: 'date'),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'open', 'closed']),
    new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'pools', type: 'array', items: new OA\Items(ref: '#/components/schemas/TalentBudgetPool')),
    new OA\Property(property: 'guidelines', type: 'array', items: new OA\Items(ref: '#/components/schemas/TalentGuideline')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'TalentCompCycleRequest', type: 'object', required: ['name', 'fiscal_year', 'currency', 'starts_on', 'ends_on', 'effective_on'], properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 150),
    new OA\Property(property: 'fiscal_year', type: 'integer'),
    new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
    new OA\Property(property: 'starts_on', type: 'string', format: 'date'),
    new OA\Property(property: 'ends_on', type: 'string', format: 'date'),
    new OA\Property(property: 'effective_on', type: 'string', format: 'date'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'TalentGuideline', type: 'object', properties: [
    new OA\Property(property: 'performance_rating', type: 'integer', minimum: 1, maximum: 5),
    new OA\Property(property: 'min_percent', type: 'string', example: '2.00'),
    new OA\Property(property: 'max_percent', type: 'string', example: '4.00'),
])]
#[OA\Schema(schema: 'TalentGuidelinesRequest', type: 'object', required: ['guidelines'], properties: [
    new OA\Property(property: 'guidelines', type: 'array', items: new OA\Items(ref: '#/components/schemas/TalentGuideline')),
])]
#[OA\Schema(schema: 'TalentCompCycleSummary', type: 'object', properties: [
    new OA\Property(property: 'cycle_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'budget_amount', type: 'string', example: '50000.00'),
    new OA\Property(property: 'committed_amount', description: 'Submitted and approved proposals', type: 'string'),
    new OA\Property(property: 'approved_amount', type: 'string'),
    new OA\Property(property: 'remaining_amount', type: 'string'),
    new OA\Property(property: 'approved_increase_percent', description: 'Approved increases over the salaries of the approved proposals', type: 'number'),
    new OA\Property(property: 'proposals_by_status', type: 'object', properties: [new OA\Property(property: 'draft', type: 'integer'), new OA\Property(property: 'submitted', type: 'integer'), new OA\Property(property: 'approved', type: 'integer'), new OA\Property(property: 'rejected', type: 'integer')]),
    new OA\Property(property: 'exceptions_count', type: 'integer'),
    new OA\Property(property: 'pools', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'pool_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'budget_amount', type: 'string'),
        new OA\Property(property: 'committed_amount', type: 'string'),
        new OA\Property(property: 'approved_amount', type: 'string'),
        new OA\Property(property: 'remaining_amount', type: 'string'),
        new OA\Property(property: 'utilization_percent', type: 'number'),
        new OA\Property(property: 'proposals_count', type: 'integer'),
    ])),
])]
#[OA\Schema(schema: 'TalentBudgetPool', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'cycle_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'department_id', description: 'Department of human resources the pool is limited to', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'budget_amount', type: 'string', example: '25000.00'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'TalentBudgetPoolRequest', type: 'object', required: ['cycle_id', 'name', 'budget_amount'], properties: [
    new OA\Property(property: 'cycle_id', description: 'Required on creation, fixed afterwards', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string', maxLength: 150),
    new OA\Property(property: 'department_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'budget_amount', type: 'string', example: '25000'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'TalentMeritProposal', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'cycle_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'budget_pool_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'pool', type: 'object', properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'name', type: 'string')]),
    new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'employee', ref: '#/components/schemas/TalentEmployeeSummary'),
    new OA\Property(property: 'performance_rating', type: 'integer', minimum: 1, maximum: 5),
    new OA\Property(property: 'current_salary', description: 'Base annual salary of human resources when the proposal was made', type: 'string', example: '40000.00'),
    new OA\Property(property: 'increase_percent', type: 'string', example: '3.00'),
    new OA\Property(property: 'increase_amount', type: 'string', example: '1200.00'),
    new OA\Property(property: 'new_salary', type: 'string', example: '41200.00'),
    new OA\Property(property: 'is_exception', description: 'The percent is outside the guideline of the rating', type: 'boolean'),
    new OA\Property(property: 'justification', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'submitted', 'approved', 'rejected']),
    new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'decided_by_user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'decided_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'decision_note', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'TalentMeritProposalRequest', type: 'object', required: ['cycle_id', 'employee_id', 'budget_pool_id', 'performance_rating', 'increase_percent'], properties: [
    new OA\Property(property: 'cycle_id', description: 'Required on creation, fixed afterwards', type: 'string', format: 'uuid'),
    new OA\Property(property: 'employee_id', description: 'Required on creation, fixed afterwards', type: 'string', format: 'uuid'),
    new OA\Property(property: 'budget_pool_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'performance_rating', type: 'integer', minimum: 1, maximum: 5),
    new OA\Property(property: 'increase_percent', type: 'string', example: '3'),
    new OA\Property(property: 'justification', description: 'Required when the percent is outside the guideline of the rating', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'TalentDecisionRequest', type: 'object', properties: [
    new OA\Property(property: 'decision_note', description: 'Optional to approve, required to reject', type: 'string', maxLength: 2000, nullable: true),
])]
#[OA\Schema(schema: 'TalentCourse', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'delivery', type: 'string', enum: ['online', 'classroom', 'blended']),
    new OA\Property(property: 'duration_hours', type: 'string', example: '2.5'),
    new OA\Property(property: 'is_mandatory', type: 'boolean'),
    new OA\Property(property: 'validity_months', description: 'Months the certificate stays valid; null for no expiry', type: 'integer', nullable: true),
    new OA\Property(property: 'pass_score', type: 'integer', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'published', 'archived']),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'TalentCourseRequest', type: 'object', required: ['code', 'title', 'delivery', 'duration_hours'], properties: [
    new OA\Property(property: 'code', description: 'Unique in the company', type: 'string', maxLength: 40),
    new OA\Property(property: 'title', type: 'string', maxLength: 200),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'delivery', type: 'string', enum: ['online', 'classroom', 'blended']),
    new OA\Property(property: 'duration_hours', type: 'string', example: '2.5'),
    new OA\Property(property: 'is_mandatory', type: 'boolean'),
    new OA\Property(property: 'validity_months', type: 'integer', nullable: true),
    new OA\Property(property: 'pass_score', type: 'integer', minimum: 1, maximum: 100, nullable: true),
])]
#[OA\Schema(schema: 'TalentLearningPath', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'published', 'archived']),
    new OA\Property(property: 'courses', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'position', type: 'integer'),
        new OA\Property(property: 'course', type: 'object', nullable: true, properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'title', type: 'string'), new OA\Property(property: 'duration_hours', type: 'string'), new OA\Property(property: 'status', type: 'string')]),
    ])),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'TalentLearningPathRequest', type: 'object', required: ['name', 'course_ids'], properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 150),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'course_ids', description: 'Courses in the order of the path', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
])]
#[OA\Schema(schema: 'TalentPathEnrolmentRequest', type: 'object', required: ['employee_ids'], properties: [
    new OA\Property(property: 'employee_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
    new OA\Property(property: 'due_on', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'TalentEnrolment', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'course_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'course', type: 'object', properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'title', type: 'string'), new OA\Property(property: 'is_mandatory', type: 'boolean'), new OA\Property(property: 'pass_score', type: 'integer', nullable: true)]),
    new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'employee', ref: '#/components/schemas/TalentEmployeeSummary'),
    new OA\Property(property: 'learning_path_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['enrolled', 'in_progress', 'completed', 'cancelled']),
    new OA\Property(property: 'progress_percent', type: 'integer'),
    new OA\Property(property: 'due_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'is_overdue', type: 'boolean'),
    new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'score', type: 'string', nullable: true),
    new OA\Property(property: 'certificate', type: 'object', nullable: true, properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'certificate_number', type: 'string'), new OA\Property(property: 'expires_on', type: 'string', format: 'date', nullable: true)]),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'TalentEnrolmentRequest', type: 'object', required: ['course_id', 'employee_id'], properties: [
    new OA\Property(property: 'course_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'due_on', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'TalentProgressRequest', type: 'object', required: ['progress_percent'], properties: [
    new OA\Property(property: 'progress_percent', type: 'integer', minimum: 0, maximum: 100),
])]
#[OA\Schema(schema: 'TalentCompleteEnrolmentRequest', type: 'object', properties: [
    new OA\Property(property: 'score', description: 'Required when the course has a pass score', type: 'number', minimum: 0, maximum: 100, nullable: true),
])]
#[OA\Schema(schema: 'TalentCertificate', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'enrolment_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'course_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'course', type: 'object', properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'title', type: 'string')]),
    new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'employee', ref: '#/components/schemas/TalentEmployeeSummary'),
    new OA\Property(property: 'certificate_number', type: 'string', example: 'CERT-2027-00001'),
    new OA\Property(property: 'issued_on', type: 'string', format: 'date'),
    new OA\Property(property: 'expires_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'is_valid', type: 'boolean'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'TalentCompliance', type: 'object', properties: [
    new OA\Property(property: 'compliance_percent', type: 'number'),
    new OA\Property(property: 'courses', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'course', type: 'object', properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'title', type: 'string')]),
        new OA\Property(property: 'employees_total', type: 'integer'),
        new OA\Property(property: 'compliant', type: 'integer'),
        new OA\Property(property: 'in_progress', type: 'integer'),
        new OA\Property(property: 'overdue', type: 'integer'),
        new OA\Property(property: 'expired', type: 'integer'),
        new OA\Property(property: 'missing', type: 'integer'),
        new OA\Property(property: 'compliance_percent', type: 'number'),
        new OA\Property(property: 'non_compliant', description: 'Only with the course_id filter', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'employee', ref: '#/components/schemas/TalentEmployeeSummary'), new OA\Property(property: 'state', type: 'string', enum: ['in_progress', 'overdue', 'expired', 'missing']), new OA\Property(property: 'due_on', type: 'string', format: 'date', nullable: true)])),
    ])),
])]
#[OA\Schema(schema: 'TalentSurvey', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'open', 'closed']),
    new OA\Property(property: 'is_anonymous', type: 'boolean'),
    new OA\Property(property: 'min_group_size', type: 'integer', minimum: 3, maximum: 50),
    new OA\Property(property: 'opens_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'closes_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'has_answered', description: 'Whether the logged-in employee answered (lists and show only)', type: 'boolean'),
    new OA\Property(property: 'questions', type: 'array', items: new OA\Items(ref: '#/components/schemas/TalentSurveyQuestion')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'TalentSurveyQuestion', type: 'object', required: ['prompt', 'question_type'], properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'position', type: 'integer'),
    new OA\Property(property: 'prompt', type: 'string', maxLength: 1000),
    new OA\Property(property: 'question_type', description: 'rating 1 to 5, enps 0 to 10, text free answer', type: 'string', enum: ['rating', 'enps', 'text']),
    new OA\Property(property: 'is_required', type: 'boolean'),
])]
#[OA\Schema(schema: 'TalentSurveyRequest', type: 'object', required: ['title', 'questions'], properties: [
    new OA\Property(property: 'title', type: 'string', maxLength: 200),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'is_anonymous', type: 'boolean', default: true),
    new OA\Property(property: 'min_group_size', type: 'integer', minimum: 3, maximum: 50, default: 5),
    new OA\Property(property: 'opens_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'closes_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'questions', description: 'The whole list, in order', type: 'array', items: new OA\Items(ref: '#/components/schemas/TalentSurveyQuestion')),
])]
#[OA\Schema(schema: 'TalentSurveyResponseRequest', type: 'object', required: ['answers'], properties: [
    new OA\Property(property: 'answers', type: 'array', items: new OA\Items(type: 'object', required: ['question_id'], properties: [
        new OA\Property(property: 'question_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'value', description: 'Rating (1-5) or eNPS (0-10) answer', type: 'integer', nullable: true),
        new OA\Property(property: 'text', description: 'Free text answer', type: 'string', maxLength: 2000, nullable: true),
    ])),
])]
#[OA\Schema(schema: 'TalentSurveyResults', type: 'object', properties: [
    new OA\Property(property: 'survey_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'status', type: 'string'),
    new OA\Property(property: 'is_anonymous', type: 'boolean'),
    new OA\Property(property: 'min_group_size', type: 'integer'),
    new OA\Property(property: 'invited_count', description: 'Active employees', type: 'integer'),
    new OA\Property(property: 'responses_count', type: 'integer'),
    new OA\Property(property: 'participation_rate_percent', type: 'number'),
    new OA\Property(property: 'results_visible', description: 'False while the responses are fewer than min_group_size (anonymous surveys): questions and segments are then empty', type: 'boolean'),
    new OA\Property(property: 'hidden_segments', description: 'Some departments are not shown because their group is too small', type: 'boolean'),
    new OA\Property(property: 'segments', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'segment', description: 'Department name; null for the merged "other" group', type: 'string', nullable: true), new OA\Property(property: 'is_other', type: 'boolean'), new OA\Property(property: 'responses_count', type: 'integer')])),
    new OA\Property(property: 'questions', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'question_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'position', type: 'integer'),
        new OA\Property(property: 'prompt', type: 'string'),
        new OA\Property(property: 'question_type', type: 'string', enum: ['rating', 'enps', 'text']),
        new OA\Property(property: 'answers_count', type: 'integer'),
        new OA\Property(property: 'average', description: 'Rating questions', type: 'number', nullable: true),
        new OA\Property(property: 'distribution', description: 'Rating questions: answers per value 1 to 5', type: 'object'),
        new OA\Property(property: 'promoters', description: 'eNPS questions: answers of 9 or 10', type: 'integer'),
        new OA\Property(property: 'passives', type: 'integer'),
        new OA\Property(property: 'detractors', description: 'Answers of 0 to 6', type: 'integer'),
        new OA\Property(property: 'enps', description: 'Promoters minus detractors, in percent of the answers', type: 'number', nullable: true),
        new OA\Property(property: 'texts', description: 'Text questions: the answers in random order', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'segments', description: 'Rating and eNPS questions: the same figures per visible segment', type: 'array', items: new OA\Items(type: 'object')),
    ])),
])]
#[OA\Schema(schema: 'TalentLearningDashboard', type: 'object', properties: [
    new OA\Property(property: 'learning', type: 'object', properties: [
        new OA\Property(property: 'courses_published', type: 'integer'),
        new OA\Property(property: 'learning_paths_published', type: 'integer'),
        new OA\Property(property: 'enrolments_by_status', type: 'object', properties: [new OA\Property(property: 'enrolled', type: 'integer'), new OA\Property(property: 'in_progress', type: 'integer'), new OA\Property(property: 'completed', type: 'integer'), new OA\Property(property: 'cancelled', type: 'integer')]),
        new OA\Property(property: 'completion_rate_percent', type: 'number'),
        new OA\Property(property: 'overdue_enrolments', type: 'integer'),
        new OA\Property(property: 'certificates_issued', type: 'integer'),
        new OA\Property(property: 'certificates_expired', type: 'integer'),
        new OA\Property(property: 'certificates_expiring_90_days', type: 'integer'),
        new OA\Property(property: 'mandatory_compliance_percent', type: 'number'),
    ]),
    new OA\Property(property: 'engagement', description: 'Latest open or closed survey; null when there is none', type: 'object', nullable: true, properties: [
        new OA\Property(property: 'survey_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'responses_count', type: 'integer'),
        new OA\Property(property: 'participation_rate_percent', type: 'number'),
        new OA\Property(property: 'results_visible', type: 'boolean'),
        new OA\Property(property: 'enps', description: 'Null without an eNPS question or below the anonymity threshold', type: 'object', nullable: true, properties: [new OA\Property(property: 'score', type: 'number', nullable: true), new OA\Property(property: 'promoters', type: 'integer'), new OA\Property(property: 'passives', type: 'integer'), new OA\Property(property: 'detractors', type: 'integer')]),
    ]),
    new OA\Property(property: 'surveys_open', type: 'integer'),
])]
final class TalentLearningOpenApi {}
