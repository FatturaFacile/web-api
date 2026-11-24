<?php

declare(strict_types=1);

namespace Modules\Manufacturing\OpenApi;

use OpenApi\Attributes as OA;

/**
 * The service endpoints of the MES Core integration (issue #184): signature security scheme and the pull and feedback
 * operations. They are authenticated by `X-Mes-Signature` (HMAC-SHA256 of `t + "." + raw body`), not by a user session.
 */
#[OA\Tag(name: 'Manufacturing MES Integration', description: 'Reference data pulled by the MES Core and the OEE, alarm and production-count events it posts back')]
#[OA\SecurityScheme(securityScheme: 'mesSignature', type: 'apiKey', description: 'X-Mes-Signature: t=<unix>,kid=<key id>,v1=<hex HMAC-SHA256(secret[kid], t + "." + raw body)>, with the tenant in X-Tenant-Id', name: 'X-Mes-Signature', in: 'header')]
#[OA\Get(
    path: '/api/v1/manufacturing/mes-sync/{resource}',
    operationId: 'pullMfgMesResource',
    description: 'A page of changes of machines, work-orders, shift-calendars or reason-codes after the sequence number the MES Core already holds (`after_seq`, or `updated_since` on the first call). Each item is {id, seq, revision, deleted, payload}; removed entities come as `deleted: true` tombstones; an after_seq older than the pruned change log answers 410 Gone.',
    summary: 'Pull MES reference data',
    security: [['mesSignature' => []]],
    tags: ['Manufacturing MES Integration'],
    parameters: [
        new OA\Parameter(name: 'resource', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['machines', 'work-orders', 'shift-calendars', 'reason-codes'])),
        new OA\Parameter(name: 'after_seq', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 0)),
        new OA\Parameter(name: 'updated_since', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
        new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Page of changes with next_seq, has_more and watermark'),
        new OA\Response(response: 400, description: 'Malformed cursor', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 401, description: 'Missing, expired or invalid signature', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'The key may not address this tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 410, description: 'Cursor no longer available: bootstrap again', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ],
)]
#[OA\Get(
    path: '/api/v1/manufacturing/mes-sync/{resource}/manifest',
    operationId: 'manifestMfgMesResource',
    description: 'A page of {id, revision} pairs of the live entities, so the MES Core can detect and repair drift of its replica (a replica row that is not listed is an orphan).',
    summary: 'MES reference data manifest',
    security: [['mesSignature' => []]],
    tags: ['Manufacturing MES Integration'],
    parameters: [
        new OA\Parameter(name: 'resource', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['machines', 'work-orders', 'shift-calendars', 'reason-codes'])),
        new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Page of entity and revision pairs'),
        new OA\Response(response: 401, description: 'Missing, expired or invalid signature', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'The key may not address this tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ],
)]
#[OA\Post(
    path: '/api/v1/manufacturing/mes-feedback/events',
    operationId: 'postMfgMesFeedbackEvents',
    description: 'A batch of OEE snapshot, production-count and alarm events. The answer is 207 Multi-Status with one result per event: applied, duplicate, stale or rejected (with a problem document).',
    summary: 'Post MES feedback events',
    security: [['mesSignature' => []]],
    tags: ['Manufacturing MES Integration'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'events', type: 'array', items: new OA\Items(type: 'object'))])),
    responses: [
        new OA\Response(response: 207, description: 'One result per event, in order'),
        new OA\Response(response: 401, description: 'Missing, expired or invalid signature', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'The key may not address this tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Invalid envelope', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ],
)]
#[OA\Post(
    path: '/api/v1/manufacturing/mes-events',
    operationId: 'postMfgMesOutboxEvents',
    description: 'A batch of outbox messages {id, type, dedupe_key, occurred_at, payload} as the MES Core relay sends them: OeeShiftClosed, OeeOrderCompleted, OeeSnapshotRevised, ProductionCountCheckpoint and MachineAlarmRaised. The answer is 207 Multi-Status with {id, status, message} per message: applied, duplicate, stale or rejected. A message id is applied once, so a redelivery never repeats a posting.',
    summary: 'Post MES outbox messages',
    security: [['mesSignature' => []]],
    tags: ['Manufacturing MES Integration'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'events', type: 'array', items: new OA\Items(type: 'object'))])),
    responses: [
        new OA\Response(response: 207, description: 'One result per message, in order'),
        new OA\Response(response: 401, description: 'Missing, expired or invalid signature', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'The key may not address this tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Invalid envelope', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ],
)]
final class MesIntegrationOpenApi {}
