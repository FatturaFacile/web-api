<?php

declare(strict_types=1);

namespace Modules\Associations\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request and response payloads of meetings, ballots and minutes.
 */
#[OA\Schema(
    schema: 'AssociationRecord',
    description: 'A record of the module with its visible attributes. Encrypted personal fields are returned decrypted only to roles that can read them.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
    additionalProperties: true,
)]
#[OA\Schema(
    schema: 'AssociationMeetingRequest',
    properties: [
        new OA\Property(property: 'body', type: 'string', enum: ['assembly', 'board', 'other']),
        new OA\Property(property: 'body_name', description: 'Required for another body', type: 'string', nullable: true),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'subject', type: 'string', nullable: true),
        new OA\Property(property: 'scheduled_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'second_call_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'location', type: 'string', nullable: true),
        new OA\Property(property: 'online_url', type: 'string', format: 'uri', nullable: true),
        new OA\Property(property: 'first_call_quorum_percent', description: 'From the statute: share of the eligible voters needed on first call', type: 'number', nullable: true),
        new OA\Property(property: 'second_call_quorum_percent', description: 'From the statute; empty means no quorum required on second call beyond one voter', type: 'number', nullable: true),
        new OA\Property(property: 'proxies_allowed', type: 'boolean'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'AssociationMeeting',
    allOf: [new OA\Schema(ref: '#/components/schemas/AssociationRecord'), new OA\Schema(properties: [
        new OA\Property(property: 'body', type: 'string', enum: ['assembly', 'board', 'other']),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'convened', 'held', 'closed', 'cancelled']),
        new OA\Property(property: 'scheduled_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'held_on_call', type: 'string', enum: ['first', 'second'], nullable: true),
        new OA\Property(property: 'agenda_items', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssociationRecord')),
        new OA\Property(property: 'participants', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssociationRecord')),
    ], type: 'object')],
)]
#[OA\Schema(schema: 'AssociationAgendaItemRequest', required: ['title'], properties: [
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
], type: 'object')]
#[OA\Schema(schema: 'AssociationAgendaDecisionRequest', required: ['approved'], properties: [
    new OA\Property(property: 'approved', type: 'boolean'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
], type: 'object')]
#[OA\Schema(schema: 'AssociationFileRequest', required: ['file'], properties: [
    new OA\Property(property: 'file', type: 'string', format: 'binary'),
], type: 'object')]
#[OA\Schema(schema: 'AssociationParticipantsRequest', required: ['source'], properties: [
    new OA\Property(property: 'source', type: 'string', enum: ['members', 'board', 'registry_type', 'selected']),
    new OA\Property(property: 'member_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
    new OA\Property(property: 'registry_type_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'social_role_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
    new OA\Property(property: 'eligible', description: 'Whether the people added can vote; minors never can', type: 'boolean'),
], type: 'object')]
#[OA\Schema(schema: 'AssociationSendDocumentRequest', required: ['channel', 'mode'], properties: [
    new OA\Property(property: 'channel', type: 'string', enum: ['email', 'sms', 'whatsapp', 'telegram']),
    new OA\Property(property: 'mode', type: 'string', enum: ['all', 'unreached', 'selected']),
    new OA\Property(property: 'member_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
], type: 'object')]
#[OA\Schema(schema: 'AssociationDeliveryList', type: 'array', items: new OA\Items(properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'member_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'channel', type: 'string', enum: ['email', 'sms', 'whatsapp', 'telegram', 'letter']),
    new OA\Property(property: 'status', type: 'string', enum: ['sent', 'printed', 'simulated', 'failed', 'no_recipient']),
    new OA\Property(property: 'attempted_at', type: 'string', format: 'date-time'),
], type: 'object'))]
#[OA\Schema(schema: 'AssociationMeetingAttendanceRequest', required: ['entries'], properties: [
    new OA\Property(property: 'entries', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'participant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'attendance', type: 'string', enum: ['invited', 'present', 'online', 'absent']),
    ], type: 'object')),
], type: 'object')]
#[OA\Schema(schema: 'AssociationProxyRequest', required: ['grantor_participant_id', 'holder_participant_id'], properties: [
    new OA\Property(property: 'grantor_participant_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'holder_participant_id', type: 'string', format: 'uuid'),
], type: 'object')]
#[OA\Schema(schema: 'AssociationHoldMeetingRequest', required: ['call'], properties: [
    new OA\Property(property: 'call', type: 'string', enum: ['first', 'second']),
], type: 'object')]
#[OA\Schema(schema: 'AssociationQuorum', properties: [
    new OA\Property(property: 'call', type: 'string'),
    new OA\Property(property: 'eligible', type: 'integer'),
    new OA\Property(property: 'attending', type: 'integer'),
    new OA\Property(property: 'represented', type: 'integer'),
    new OA\Property(property: 'counted', type: 'integer'),
    new OA\Property(property: 'required_percent', type: 'string', nullable: true),
    new OA\Property(property: 'required', type: 'integer', nullable: true),
    new OA\Property(property: 'configured', type: 'boolean'),
    new OA\Property(property: 'reached', type: 'boolean'),
], type: 'object')]
#[OA\Schema(schema: 'AssociationBallotRequest', required: ['question'], properties: [
    new OA\Property(property: 'question', type: 'string'),
    new OA\Property(property: 'options', type: 'array', items: new OA\Items(type: 'string'), example: ['yes', 'no', 'abstain']),
    new OA\Property(property: 'is_secret', type: 'boolean'),
    new OA\Property(property: 'majority_rule', type: 'string', enum: ['simple', 'absolute', 'qualified']),
    new OA\Property(property: 'qualified_percent', type: 'number', nullable: true),
    new OA\Property(property: 'agenda_item_id', type: 'string', format: 'uuid', nullable: true),
], type: 'object')]
#[OA\Schema(
    schema: 'AssociationBallot',
    allOf: [new OA\Schema(ref: '#/components/schemas/AssociationRecord'), new OA\Schema(properties: [
        new OA\Property(property: 'question', type: 'string'),
        new OA\Property(property: 'options', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'is_secret', type: 'boolean'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'open', 'closed']),
        new OA\Property(property: 'results', description: 'Counts and outcome; by_person only for open ballots', type: 'object', nullable: true),
    ], type: 'object')],
)]
#[OA\Schema(schema: 'AssociationVoteRequest', required: ['choice'], properties: [
    new OA\Property(property: 'choice', type: 'string'),
    new OA\Property(property: 'on_behalf_of', description: 'Participant represented by proxy', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'participant_id', description: 'Staff only: the participant voting in person', type: 'string', format: 'uuid'),
], type: 'object')]
#[OA\Schema(schema: 'AssociationAreaBallotList', type: 'array', items: new OA\Items(properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'question', type: 'string'),
    new OA\Property(property: 'options', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'is_secret', type: 'boolean'),
    new OA\Property(property: 'can_vote_self', type: 'boolean'),
    new OA\Property(property: 'can_vote_for', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
], type: 'object'))]
#[OA\Schema(schema: 'AssociationMinutesRequest', properties: [
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'body', type: 'string'),
], type: 'object')]
#[OA\Schema(
    schema: 'AssociationMinutes',
    allOf: [new OA\Schema(ref: '#/components/schemas/AssociationRecord'), new OA\Schema(properties: [
        new OA\Property(property: 'meeting_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'approved']),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'body', type: 'string'),
        new OA\Property(property: 'number', type: 'string', nullable: true),
        new OA\Property(property: 'content_hash', type: 'string', nullable: true),
        new OA\Property(property: 'approved_at', type: 'string', format: 'date-time', nullable: true),
    ], type: 'object')],
)]
final class GovernanceOpenApiSchemas {}
