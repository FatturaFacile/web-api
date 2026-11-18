<?php

declare(strict_types=1);

namespace App\OpenApi\Routes;

use OpenApi\Attributes as OA;

/**
 * OpenAPI operations of the `webhooks` routes: method, path, parameters and the standard responses. The payloads are
 * described by the FormRequest of each controller action.
 */
#[OA\Get(path: '/api/v1/webhooks/leads/{token}', operationId: 'getWebhooksLeadsVerify', description: 'LeadWebhookController::verify', summary: 'Verify (Leads)', parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], tags: ['Webhooks'], responses: [new OA\Response(response: 200, description: 'Success', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
#[OA\Post(path: '/api/v1/webhooks/leads/{token}', operationId: 'postWebhooksLeadsReceive', description: 'LeadWebhookController::receive', summary: 'Receive (Leads)', requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], tags: ['Webhooks'], responses: [new OA\Response(response: 200, description: 'Success', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))])]
final class WebhooksRoutesOpenApi {}
