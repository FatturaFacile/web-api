<?php

declare(strict_types=1);

namespace App\OpenApi\Components;

use OpenApi\Attributes as OA;

/**
 * Shared OpenAPI parameters and responses of the GDPR / Swiss nLPD compliance endpoints (settings/privacy). Schemas
 * live in PrivacyComplianceSchemas: swagger-php drops the schemas of a class that also declares parameters.
 */
#[OA\Parameter(parameter: 'PrivacyDataSubjectRequestId', name: 'id', description: 'Identifier of the data subject request of the active company', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'PrivacySubjectType', name: 'subjectType', description: 'Kind of data subject whose dossier is exported: a customer/supplier contact or an employee', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['contact', 'employee']))]
#[OA\Parameter(parameter: 'PrivacySubjectId', name: 'subjectId', description: 'Identifier of the contact or of the employee (according to subjectType)', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Response(response: 'PrivacyUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'PrivacyForbidden', description: 'The role has no access to the system settings module, or the tenant context cannot be resolved', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'PrivacyNotFound', description: 'Request or data subject not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'PrivacyUnprocessable', description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
final class PrivacyOpenApiComponents {}
