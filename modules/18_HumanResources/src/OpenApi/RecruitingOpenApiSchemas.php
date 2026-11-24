<?php

declare(strict_types=1);

namespace Modules\HumanResources\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Requests of recruiting and onboarding.
 */
#[OA\Schema(schema: 'HumanResourcesSaveJobPostingRequest', description: 'Request payload validated by SaveJobPostingRequest', required: ['title'], type: 'object', properties: [
    new OA\Property(property: 'title', type: 'string', maxLength: 180),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'department_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'position_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'location', type: 'string', nullable: true),
    new OA\Property(property: 'employment_type', type: 'string'),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'open']),
    new OA\Property(property: 'opens_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'closes_on', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'HumanResourcesStoreJobApplicationRequest', description: 'Request payload validated by StoreJobApplicationRequest', required: ['job_posting_id', 'first_name', 'last_name', 'email'], type: 'object', properties: [
    new OA\Property(property: 'job_posting_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'first_name', type: 'string', maxLength: 100),
    new OA\Property(property: 'last_name', type: 'string', maxLength: 100),
    new OA\Property(property: 'email', type: 'string', format: 'email'),
    new OA\Property(property: 'phone', type: 'string', nullable: true),
    new OA\Property(property: 'cover_note', type: 'string', nullable: true),
    new OA\Property(property: 'source', type: 'string', nullable: true),
    new OA\Property(property: 'consent_until', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'HumanResourcesStoreInterviewNoteRequest', description: 'Request payload validated by StoreInterviewNoteRequest', required: ['note'], type: 'object', properties: [
    new OA\Property(property: 'interviewed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'rating', type: 'integer', minimum: 1, maximum: 5, nullable: true),
    new OA\Property(property: 'note', type: 'string'),
])]
#[OA\Schema(schema: 'HumanResourcesHireCandidateRequest', description: 'Request payload validated by HireCandidateRequest', required: ['base_annual_salary', 'hire_date'], type: 'object', properties: [
    new OA\Property(property: 'base_annual_salary', type: 'number', minimum: 0),
    new OA\Property(property: 'hire_date', type: 'string', format: 'date'),
    new OA\Property(property: 'work_email', type: 'string', format: 'email', nullable: true),
    new OA\Property(property: 'department_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'position_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'employment_type', type: 'string', nullable: true),
    new OA\Property(property: 'contract_type', type: 'string', nullable: true),
    new OA\Property(property: 'weekly_hours', type: 'number', nullable: true),
    new OA\Property(property: 'probation_months', type: 'integer', nullable: true),
    new OA\Property(property: 'onboarding_template_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'HumanResourcesSaveOnboardingTemplateRequest', description: 'Request payload validated by SaveOnboardingTemplateRequest', required: ['name', 'tasks'], type: 'object', properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 120),
    new OA\Property(property: 'tasks', type: 'array', items: new OA\Items(required: ['title'], properties: [
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'due_after_days', description: 'Days after the start, negative before it', type: 'integer', nullable: true),
    ], type: 'object')),
])]
final class RecruitingOpenApiSchemas {}
