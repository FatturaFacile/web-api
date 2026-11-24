<?php

declare(strict_types=1);

namespace Modules\Workflow\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Shared OpenAPI path parameters of the definition graph. They live apart from the schemas: swagger-php drops every
 * schema of a class that also declares class-level parameters.
 */
#[OA\Parameter(parameter: 'WorkflowDefinitionId', name: 'id', description: 'Workflow definition identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'WorkflowNodeId', name: 'nodeId', description: 'Workflow node identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'WorkflowTransitionId', name: 'transitionId', description: 'Workflow transition identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
final class WorkflowOpenApiComponents {}
