<?php

declare(strict_types=1);

namespace Modules\HumanResources\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Recruiting',
    description: 'HR Mod 070 Recruiting & ATS: job openings, candidate pipeline (Kanban), interview scorecards, offer letters, hire to employee, GDPR / nLPD retention'
)]
#[OA\Tag(
    name: 'Public Careers',
    description: 'Unauthenticated careers page, job feeds (JSON, RSS 2.0) and public applications, resolved by careers slug'
)]
#[OA\Schema(
    schema: 'RecruitingSalaryRange',
    properties: [
        new OA\Property(property: 'min', type: 'number', format: 'double', nullable: true, example: 70000.00),
        new OA\Property(property: 'max', type: 'number', format: 'double', nullable: true, example: 90000.00),
        new OA\Property(property: 'currency', type: 'string', enum: ['EUR', 'CHF']),
        new OA\Property(property: 'period', type: 'string', enum: ['year']),
    ],
    type: 'object',
    nullable: true
)]
#[OA\Schema(
    schema: 'JobOpening',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'reference', type: 'string', example: 'JOB-2026-AB12CD'),
        new OA\Property(property: 'position_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'position_title', type: 'string', nullable: true),
        new OA\Property(property: 'department_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'department_name', type: 'string', nullable: true),
        new OA\Property(property: 'location_site', type: 'string', example: 'Lugano'),
        new OA\Property(property: 'employment_type', type: 'string', enum: ['full_time', 'part_time', 'contractor', 'apprentice', 'seasonal']),
        new OA\Property(property: 'openings_count', type: 'integer'),
        new OA\Property(property: 'hires_count', type: 'integer'),
        new OA\Property(property: 'salary_range_public', ref: '#/components/schemas/RecruitingSalaryRange'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'published', 'internal_only', 'closed_filled', 'cancelled']),
        new OA\Property(property: 'published_languages', type: 'array', items: new OA\Items(type: 'string', enum: ['it-CH', 'de-CH', 'fr-CH', 'it-IT', 'en'])),
        new OA\Property(property: 'translations', type: 'object', additionalProperties: new OA\AdditionalProperties(properties: [
            new OA\Property(property: 'title', type: 'string'),
            new OA\Property(property: 'description', type: 'string'),
            new OA\Property(property: 'requirements', type: 'string', nullable: true),
            new OA\Property(property: 'benefits', type: 'string', nullable: true),
        ], type: 'object')),
        new OA\Property(property: 'published_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'closes_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'applications_count', type: 'integer'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'CandidateApplicationCard',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'job_opening_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'job_opening_title', type: 'string'),
        new OA\Property(property: 'first_name', type: 'string'),
        new OA\Property(property: 'last_name', type: 'string'),
        new OA\Property(property: 'full_name', type: 'string'),
        new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'stage', type: 'string', enum: ['applied', 'hr_screening', 'technical_interview', 'manager_interview', 'offer_extended', 'hired', 'rejected']),
        new OA\Property(property: 'stage_entered_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'board_position', type: 'integer'),
        new OA\Property(property: 'source', type: 'string', enum: ['careers_page', 'manual', 'referral', 'job_board']),
        new OA\Property(property: 'cv_parse_status', type: 'string', enum: ['pending', 'parsed', 'partial', 'failed', 'not_provided']),
        new OA\Property(property: 'skills', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'average_score', type: 'number', format: 'double', nullable: true),
        new OA\Property(property: 'scorecards_count', type: 'integer'),
        new OA\Property(property: 'privacy_consent_expires_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_anonymized', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'CandidateApplication',
    type: 'object',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/CandidateApplicationCard'),
        new OA\Schema(properties: [
            new OA\Property(property: 'cover_letter', type: 'string', nullable: true),
            new OA\Property(property: 'notes', type: 'string', nullable: true),
            new OA\Property(property: 'locale', type: 'string'),
            new OA\Property(property: 'cv', type: 'object', nullable: true, properties: [
                new OA\Property(property: 'file_name', type: 'string'),
                new OA\Property(property: 'mime_type', type: 'string'),
                new OA\Property(property: 'size_bytes', type: 'integer'),
                new OA\Property(property: 'download_url', type: 'string', example: '/hr/recruiting/applications/{id}/cv'),
            ]),
            new OA\Property(property: 'extracted_profile', type: 'object', nullable: true),
            new OA\Property(property: 'consent', type: 'object', properties: [
                new OA\Property(property: 'given_at', type: 'string', format: 'date-time'),
                new OA\Property(property: 'text_version', type: 'string'),
                new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'extended_at', type: 'string', format: 'date-time', nullable: true),
            ]),
            new OA\Property(property: 'rejection', type: 'object', nullable: true, properties: [
                new OA\Property(property: 'reason_code', type: 'string', enum: ['not_qualified', 'salary_mismatch', 'position_filled', 'withdrawn', 'no_show', 'culture_fit', 'other']),
                new OA\Property(property: 'note', type: 'string', nullable: true),
            ]),
            new OA\Property(property: 'history', type: 'array', items: new OA\Items(type: 'object')),
            new OA\Property(property: 'emails', type: 'array', items: new OA\Items(type: 'object')),
            new OA\Property(property: 'offer', ref: '#/components/schemas/JobOfferLetter', nullable: true),
            new OA\Property(property: 'hired_employee_id', type: 'string', format: 'uuid', nullable: true),
        ], type: 'object'),
    ]
)]
#[OA\Schema(
    schema: 'InterviewScorecard',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'application_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'interviewer_user_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'interviewer_name', type: 'string', nullable: true),
        new OA\Property(property: 'stage', type: 'string', enum: ['hr_screening', 'technical_interview', 'manager_interview']),
        new OA\Property(property: 'criteria_scores', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'criterion', type: 'string'),
            new OA\Property(property: 'score', type: 'integer', maximum: 5, minimum: 1),
            new OA\Property(property: 'comment', type: 'string', nullable: true),
        ], type: 'object')),
        new OA\Property(property: 'overall_score', type: 'number', format: 'double'),
        new OA\Property(property: 'recommendation', type: 'string', enum: ['strong_hire', 'hire', 'no_hire']),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'interviewed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'JobOfferLetter',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'application_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'proposed_salary', type: 'number', format: 'double'),
        new OA\Property(property: 'currency', type: 'string', enum: ['EUR', 'CHF']),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'employment_type', type: 'string'),
        new OA\Property(property: 'contract_type', type: 'string', enum: ['permanent', 'fixed_term', 'apprenticeship', 'contractor', 'internship']),
        new OA\Property(property: 'weekly_hours', type: 'number'),
        new OA\Property(property: 'probation_months', type: 'integer'),
        new OA\Property(property: 'valid_until', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'sent', 'signed', 'declined', 'expired', 'cancelled']),
        new OA\Property(property: 'signature_status', type: 'string', enum: ['not_requested', 'pending', 'signed', 'declined']),
        new OA\Property(property: 'signature_provider', type: 'string', example: 'manual'),
        new OA\Property(property: 'signature_external_id', type: 'string', nullable: true),
        new OA\Property(property: 'rendered_body', type: 'string', description: 'HTML'),
        new OA\Property(property: 'sent_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'signed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'declined_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'RecruitingEmailTemplate',
    properties: [
        new OA\Property(property: 'stage', type: 'string'),
        new OA\Property(property: 'locale', type: 'string', enum: ['it-CH', 'de-CH', 'fr-CH', 'it-IT', 'en']),
        new OA\Property(property: 'subject', type: 'string'),
        new OA\Property(property: 'body', type: 'string', description: 'Placeholders: {{candidate_name}}, {{job_title}}, {{company_name}}, {{stage_label}}'),
        new OA\Property(property: 'is_enabled', type: 'boolean'),
        new OA\Property(property: 'is_default', type: 'boolean'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'RecruitingSettings',
    properties: [
        new OA\Property(property: 'careers', type: 'object', properties: [
            new OA\Property(property: 'enabled', type: 'boolean'),
            new OA\Property(property: 'slug', type: 'string', nullable: true, pattern: '^[a-z0-9]+(-[a-z0-9]+)*$'),
            new OA\Property(property: 'company_name', type: 'string'),
            new OA\Property(property: 'intro', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
            new OA\Property(property: 'privacy_notice', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
            new OA\Property(property: 'privacy_policy_url', type: 'string', nullable: true),
            new OA\Property(property: 'consent_text_version', type: 'string'),
        ]),
        new OA\Property(property: 'retention', type: 'object', properties: [
            new OA\Property(property: 'default_months', type: 'integer', enum: [6, 12]),
        ]),
        new OA\Property(property: 'pipeline', type: 'object', properties: [
            new OA\Property(property: 'stages', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'stage', type: 'string'),
                new OA\Property(property: 'enabled', type: 'boolean'),
                new OA\Property(property: 'auto_email', type: 'boolean'),
                new OA\Property(property: 'labels', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
            ], type: 'object')),
        ]),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PublicJob',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'reference', type: 'string'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'requirements', type: 'string', nullable: true),
        new OA\Property(property: 'benefits', type: 'string', nullable: true),
        new OA\Property(property: 'department_name', type: 'string', nullable: true),
        new OA\Property(property: 'location_site', type: 'string'),
        new OA\Property(property: 'employment_type', type: 'string'),
        new OA\Property(property: 'salary_range_public', ref: '#/components/schemas/RecruitingSalaryRange'),
        new OA\Property(property: 'locale', type: 'string'),
        new OA\Property(property: 'available_locales', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'published_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'closes_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'apply_url', type: 'string', format: 'uri'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'RecruitingRuleViolation',
    properties: [
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'error_code', type: 'string', enum: ['invalid_stage_transition', 'stage_disabled', 'opening_not_open', 'no_vacancy', 'opening_not_publishable', 'offer_not_signed', 'offer_already_exists', 'already_hired', 'candidate_anonymized', 'consent_expired', 'duplicate_application']),
    ],
    type: 'object'
)]
final class RecruitingAtsOpenApiSchemas {}
