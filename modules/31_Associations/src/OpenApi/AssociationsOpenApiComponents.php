<?php

declare(strict_types=1);

namespace Modules\Associations\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Tag, security schemes and responses shared by the association endpoints.
 */
#[OA\Tag(name: 'Associations', description: 'Registry, membership, enrolment, fees, courses, certificates, access control and member area of associations and sports clubs')]
#[OA\SecurityScheme(securityScheme: 'associationAreaToken', type: 'apiKey', description: 'Personal token of the member area', name: 'X-Area-Token', in: 'header')]
#[OA\SecurityScheme(securityScheme: 'associationDeviceKey', type: 'apiKey', description: 'Public key of a reader', name: 'X-Device-Key', in: 'header')]
#[OA\SecurityScheme(securityScheme: 'associationDeviceSecret', type: 'apiKey', description: 'Secret of a reader', name: 'X-Device-Secret', in: 'header')]
#[OA\Response(response: 'AssociationUnauthenticated', description: 'Missing or invalid credentials', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'AssociationForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'AssociationNotFound', description: 'Not found for this company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'AssociationConflict', description: 'The state of the record or a capacity limit refuses the operation', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'AssociationUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
final class AssociationsOpenApiComponents {}
