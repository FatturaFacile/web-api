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

/**
 * Security, Privacy & Compliance Center: posture KPIs and just-in-time PII unmasking.
 */
final class SecurityCenterController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function posture(Request $request, SecurityPostureService $posture): JsonResponse
    {
        return $this->successResponse($posture->forTenant($this->tenantId($request)));
    }

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
