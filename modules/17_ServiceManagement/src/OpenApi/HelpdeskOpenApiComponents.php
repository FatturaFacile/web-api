<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Shared OpenAPI parameters of the helpdesk. They live apart from the schemas: swagger-php drops every schema of a class
 * that also declares class-level parameters.
 */
#[OA\Parameter(parameter: 'HelpdeskTicketPath', name: 'ticket', description: 'Identifier of the ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'HelpdeskAttachmentPath', name: 'attachment', description: 'Identifier of the attachment', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'HelpdeskUserPath', name: 'user', description: 'Identifier of the user', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'HelpdeskIdPath', name: 'id', description: 'Identifier of the record', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'HelpdeskPortalToken', name: 'token', description: 'Customer portal magic-link token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
#[OA\Parameter(parameter: 'HelpdeskSurveyToken', name: 'token', description: 'Secret token of the satisfaction survey link', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
#[OA\Parameter(parameter: 'HelpdeskPeriodFrom', name: 'from', description: 'First day of the period (inclusive)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))]
#[OA\Parameter(parameter: 'HelpdeskPeriodTo', name: 'to', description: 'Last day of the period (inclusive)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))]
#[OA\Parameter(parameter: 'HelpdeskSearchQuery', name: 'q', description: 'Free-text search', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
#[OA\Parameter(parameter: 'HelpdeskPage', name: 'page', description: 'Page number', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1))]
final class HelpdeskOpenApiComponents {}
