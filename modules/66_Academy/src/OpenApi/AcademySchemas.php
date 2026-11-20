<?php

declare(strict_types=1);

namespace Modules\Academy\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request and response payloads of the Academy / LMS.
 */
#[OA\Tag(name: 'Academy', description: 'Learning tracks, hands-on sandbox missions, contextual help, verifiable certificates and classroom cohorts')]
#[OA\Schema(schema: 'AcademyContextualHelpResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'route_pattern', type: 'string', example: '/invoices/:id'),
    new OA\Property(property: 'locale', type: 'string', enum: ['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT'], description: 'Language actually served, after the fallback'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'body_markdown', type: 'string'),
    new OA\Property(property: 'field_tooltips', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string'), description: 'Tooltip text by field key'),
])]
#[OA\Schema(schema: 'AcademyMissionProgress', type: 'object', properties: [
    new OA\Property(property: 'mission_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'track_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'status', type: 'string', enum: ['started', 'completed']),
    new OA\Property(property: 'completed', type: 'boolean'),
    new OA\Property(property: 'xp_awarded', type: 'integer'),
    new OA\Property(property: 'badge_code', type: 'string', nullable: true),
    new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'AcademyTourStep', type: 'object', properties: [
    new OA\Property(property: 'route', type: 'string'),
    new OA\Property(property: 'selector', type: 'string', description: 'CSS selector of the element to spotlight'),
    new OA\Property(property: 'placement', type: 'string', enum: ['top', 'bottom', 'left', 'right']),
    new OA\Property(property: 'text', type: 'string'),
])]
#[OA\Schema(schema: 'AcademyMission', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'sequence', type: 'integer'),
    new OA\Property(property: 'difficulty', type: 'string', enum: ['beginner', 'intermediate', 'advanced']),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'xp_reward', type: 'integer'),
    new OA\Property(property: 'badge_code', type: 'string', nullable: true),
    new OA\Property(property: 'requires_sandbox', type: 'boolean'),
    new OA\Property(property: 'status', type: 'string', enum: ['not_started', 'started', 'completed']),
    new OA\Property(property: 'tour_steps', type: 'array', items: new OA\Items(ref: '#/components/schemas/AcademyTourStep')),
])]
#[OA\Schema(schema: 'AcademyTrackProgress', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string', enum: ['swiss-accounting-kmu', 'italian-fatturapa-and-iva', 'warehouse-and-mrp-mastery', 'hr-and-payroll-ch-it', 'erp-developer-architecture']),
    new OA\Property(property: 'difficulty', type: 'string', enum: ['beginner', 'intermediate', 'advanced']),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'missions_total', type: 'integer'),
    new OA\Property(property: 'missions_completed', type: 'integer'),
    new OA\Property(property: 'completion_percent', type: 'integer'),
    new OA\Property(property: 'xp_earned', type: 'integer'),
    new OA\Property(property: 'certificate_code', type: 'string', nullable: true),
    new OA\Property(property: 'missions', type: 'array', items: new OA\Items(ref: '#/components/schemas/AcademyMission')),
])]
#[OA\Schema(schema: 'AcademyProgress', type: 'object', properties: [
    new OA\Property(property: 'total_xp', type: 'integer'),
    new OA\Property(property: 'badges', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'tracks', type: 'array', items: new OA\Items(ref: '#/components/schemas/AcademyTrackProgress')),
])]
#[OA\Schema(schema: 'AcademyCertificateVerification', type: 'object', properties: [
    new OA\Property(property: 'valid', type: 'boolean'),
    new OA\Property(property: 'certificate_code', type: 'string'),
    new OA\Property(property: 'holder_name', type: 'string'),
    new OA\Property(property: 'track_code', type: 'string'),
    new OA\Property(property: 'track_title', type: 'string'),
    new OA\Property(property: 'total_xp', type: 'integer'),
    new OA\Property(property: 'issued_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CreateAcademyClassroomCohortRequest', required: ['name'], type: 'object', properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 160),
    new OA\Property(property: 'track_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), nullable: true),
    new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'JoinAcademyClassroomRequest', required: ['join_code'], type: 'object', properties: [
    new OA\Property(property: 'join_code', type: 'string', minLength: 4, maxLength: 12),
])]
#[OA\Schema(schema: 'AcademyClassroomCohort', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'join_code', type: 'string'),
    new OA\Property(property: 'teacher_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'AcademyClassroomDashboard', type: 'object', properties: [
    new OA\Property(property: 'cohort', ref: '#/components/schemas/AcademyClassroomCohort'),
    new OA\Property(property: 'assignments', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'track_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'track_code', type: 'string', nullable: true),
        new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
    ])),
    new OA\Property(property: 'students', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'enrolled_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'missions_started', type: 'integer'),
        new OA\Property(property: 'missions_completed', type: 'integer'),
        new OA\Property(property: 'missions_total', type: 'integer'),
        new OA\Property(property: 'total_xp', type: 'integer'),
        new OA\Property(property: 'certificates', type: 'array', items: new OA\Items(type: 'string')),
    ])),
])]
final class AcademySchemas {}
