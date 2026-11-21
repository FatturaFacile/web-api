<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Security\UnmaskPiiRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Security\PiiUnmaskService;
use App\Services\Security\SecurityPostureService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Security, Privacy & Compliance Center: posture KPIs and just-in-time PII unmasking.
 */
final class SecurityCenterController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(
        path: '/api/v1/security/posture',
        operationId: 'securityPosture',
        description: 'KPIs of the Security, Privacy & Compliance Center: audit chain integrity, encrypted-field coverage, open DSAR, legal holds, open incidents and readiness score.',
        summary: 'Security and compliance posture',
        security: [['bearerAuth' => []]],
        tags: ['Security Center'],
        responses: [
            new OA\Response(response: 200, description: 'Posture KPIs', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 403, description: 'The permission audit.view is missing'),
        ]
    )]
    public function posture(Request $request, SecurityPostureService $posture): JsonResponse
    {
        return $this->successResponse($posture->forTenant($this->tenantId($request)));
    }

    #[OA\Post(
        path: '/api/v1/security/pii/unmask',
        operationId: 'unmaskPii',
        description: 'Returns the clear value of one encrypted field and writes a PII_UNMASKED audit record with the justification.',
        summary: 'Reveal a masked personal data field',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['entity_type', 'entity_id', 'field', 'justification'], properties: [
            new OA\Property(property: 'entity_type', type: 'string', example: 'contact'),
            new OA\Property(property: 'entity_id', type: 'string'),
            new OA\Property(property: 'field', type: 'string', example: 'iban'),
            new OA\Property(property: 'justification', type: 'string', minLength: 10),
        ])),
        tags: ['Security Center'],
        responses: [
            new OA\Response(response: 200, description: 'The clear value', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 403, description: 'The permission privacy.pii.unmask is missing'),
            new OA\Response(response: 404, description: 'Unknown record or field'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function unmask(UnmaskPiiRequest $request, PiiUnmaskService $service): JsonResponse
    {
        try {
            $value = $service->reveal(
                (string) $request->string('entity_type'),
                (string) $request->string('entity_id'),
                (string) $request->string('field'),
                (string) $request->string('justification'),
                $this->tenantId($request),
            );
        } catch (ModelNotFoundException) {
            abort(404, 'The field was not found.');
        }

        return $this->successResponse(['value' => $value]);
    }

    private function tenantId(Request $request): string
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;

        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        return (string) $tenantId;
    }
}
