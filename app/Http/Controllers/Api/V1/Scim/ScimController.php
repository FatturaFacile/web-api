<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Scim;

use App\Http\Controllers\Controller;
use App\Services\Auth\Scim\ScimException;
use App\Services\Auth\Scim\ScimProvisioningLogger;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the SCIM 2.0 controllers share: `application/scim+json` responses, RFC 7644 error bodies, the tenant and token of
 * the request, and the provisioning log of every write (see ScimProvisioningLogger).
 */
abstract class ScimController extends Controller
{
    protected const SCIM_CONTENT_TYPE = 'application/scim+json';

    public function __construct(
        protected readonly TenantContext $tenantContext,
        protected readonly ScimProvisioningLogger $provisioningLog,
    ) {}

    /**
     * @param  callable(): array<string, mixed>  $action
     */
    protected function respond(callable $action, int $status = Response::HTTP_OK): JsonResponse
    {
        try {
            return response()->json($action(), $status, ['Content-Type' => self::SCIM_CONTENT_TYPE]);
        } catch (ScimException $exception) {
            return $this->error($exception);
        }
    }

    /**
     * A write: the outcome goes to the provisioning log, success or failure.
     *
     * @param  'user'|'group'  $resource
     * @param  callable(): array<string, mixed>  $action  returns the SCIM resource
     */
    protected function write(Request $request, string $resource, string $operation, ?string $id, callable $action, int $status = Response::HTTP_OK): JsonResponse
    {
        try {
            $result = $action();
        } catch (ScimException $exception) {
            $this->provisioningLog->failure($this->tenantId(), $resource, $operation, $id, null, $this->tokenId($request), $exception->getMessage());

            return $this->error($exception);
        }

        $this->provisioningLog->success(
            $this->tenantId(),
            $resource,
            $operation,
            (string) ($result['id'] ?? $id),
            (string) ($result['userName'] ?? $result['displayName'] ?? ''),
            $this->tokenId($request),
        );

        return response()->json($result, $status, ['Content-Type' => self::SCIM_CONTENT_TYPE]);
    }

    /**
     * A DELETE: logged, answered with 204.
     *
     * @param  'user'|'group'  $resource
     * @param  callable(): void  $action
     */
    protected function remove(Request $request, string $resource, string $id, callable $action): JsonResponse
    {
        try {
            $action();
        } catch (ScimException $exception) {
            $this->provisioningLog->failure($this->tenantId(), $resource, 'delete', $id, null, $this->tokenId($request), $exception->getMessage());

            return $this->error($exception);
        }

        $this->provisioningLog->success($this->tenantId(), $resource, 'delete', $id, null, $this->tokenId($request));

        return response()->json(null, Response::HTTP_NO_CONTENT, ['Content-Type' => self::SCIM_CONTENT_TYPE]);
    }

    protected function error(ScimException $exception): JsonResponse
    {
        $body = [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
            'status' => (string) $exception->status,
            'detail' => $exception->getMessage(),
        ];

        if ($exception->scimType !== null) {
            $body['scimType'] = $exception->scimType;
        }

        return response()->json($body, $exception->status, ['Content-Type' => self::SCIM_CONTENT_TYPE]);
    }

    protected function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }

    protected function tokenId(Request $request): string
    {
        return (string) $request->attributes->get('scim_token_id');
    }
}
