<?php

declare(strict_types=1);

namespace Modules\Collaboration\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Shared OpenAPI parameters. They live apart from the schemas: swagger-php drops every schema of a class that also
 * declares class-level parameters.
 */
#[OA\Parameter(parameter: 'CollabId', name: 'id', description: 'Comment or tag identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'CollabTagId', name: 'tagId', description: 'Tag identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'CollabRecordType', name: 'type', description: 'Record type key (contact, invoice, quote, product, project, supplier, employee, or a type another module registered)', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
#[OA\Parameter(parameter: 'CollabRecordId', name: 'recordId', description: 'Identifier of the record', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
final class CollaborationOpenApiComponents {}
