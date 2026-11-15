<?php

declare(strict_types=1);

namespace Modules\HumanResources\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'HumanResourcesSaveGoalCycleRequest', description: 'Request payload validated by SaveGoalCycleRequest', required: ['name', 'starts_on', 'ends_on'], type: 'object', properties: [
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'starts_on', type: 'string', format: 'date'),
    new OA\Property(property: 'ends_on', type: 'string', format: 'date'),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'active']),
])]
#[OA\Schema(schema: 'HumanResourcesSaveGoalRequest', description: 'Request payload validated by SaveGoalRequest', required: ['goal_cycle_id', 'level', 'title'], type: 'object', properties: [
    new OA\Property(property: 'goal_cycle_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'level', type: 'string', enum: ['company', 'team', 'employee']),
    new OA\Property(property: 'employee_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'team_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'parent_goal_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'weight', type: 'number'),
    new OA\Property(property: 'due_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'active', 'completed', 'cancelled']),
    new OA\Property(property: 'key_results', type: 'array', items: new OA\Items(required: ['metric', 'target_value'], properties: [new OA\Property(property: 'metric', type: 'string'), new OA\Property(property: 'unit', type: 'string', nullable: true), new OA\Property(property: 'start_value', type: 'number', nullable: true), new OA\Property(property: 'target_value', type: 'number'), new OA\Property(property: 'current_value', type: 'number', nullable: true)], type: 'object')),
])]
#[OA\Schema(schema: 'HumanResourcesSaveGoalKeyResultRequest', description: 'Request payload validated by SaveGoalKeyResultRequest', required: ['metric', 'target_value'], type: 'object', properties: [
    new OA\Property(property: 'metric', type: 'string'),
    new OA\Property(property: 'unit', type: 'string', nullable: true),
    new OA\Property(property: 'start_value', type: 'number', nullable: true),
    new OA\Property(property: 'target_value', type: 'number'),
    new OA\Property(property: 'current_value', type: 'number', nullable: true),
])]
#[OA\Schema(schema: 'HumanResourcesStoreGoalCheckInRequest', description: 'Request payload validated by StoreGoalCheckInRequest', required: [], type: 'object', properties: [
    new OA\Property(property: 'key_result_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'value', type: 'number', nullable: true),
    new OA\Property(property: 'progress', type: 'number', nullable: true),
    new OA\Property(property: 'comment', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'HumanResourcesSaveOffboardingTemplateRequest', description: 'Request payload validated by SaveOffboardingTemplateRequest', required: ['name', 'tasks'], type: 'object', properties: [
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'tasks', type: 'array', items: new OA\Items(required: ['title'], properties: [new OA\Property(property: 'title', type: 'string'), new OA\Property(property: 'code', type: 'string', enum: ['asset_return', 'access_revocation', 'exit_interview', 'final_settlement', 'custom'], nullable: true), new OA\Property(property: 'due_after_days', description: 'Days after the termination date, negative before it', type: 'integer', nullable: true)], type: 'object')),
])]
#[OA\Schema(schema: 'HumanResourcesStartOffboardingRequest', description: 'Request payload validated by StartOffboardingRequest', required: ['employee_id'], type: 'object', properties: [
    new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'template_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'HumanResourcesStoreExitInterviewRequest', description: 'Request payload validated by StoreExitInterviewRequest', required: ['employee_id', 'conducted_on', 'reason_code'], type: 'object', properties: [
    new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'interviewer_employee_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'conducted_on', type: 'string', format: 'date'),
    new OA\Property(property: 'reason_code', type: 'string', enum: ['resignation_better_offer', 'resignation_career_change', 'resignation_relocation', 'resignation_personal', 'dismissal_performance', 'dismissal_redundancy', 'mutual_agreement', 'contract_end', 'retirement', 'other']),
    new OA\Property(property: 'would_recommend', type: 'boolean', nullable: true),
    new OA\Property(property: 'eligible_for_rehire', type: 'boolean', nullable: true),
    new OA\Property(property: 'satisfaction_rating', type: 'integer', nullable: true),
    new OA\Property(property: 'feedback', type: 'string', nullable: true),
])]
final class GoalsAndOffboardingOpenApiSchemas {}
