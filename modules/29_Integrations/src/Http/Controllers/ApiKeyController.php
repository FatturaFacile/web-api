<?php

declare(strict_types=1);

namespace Modules\Integrations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Closure;
use Enterprise\MultiTenant\Services\ModuleAccessGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Models\ApiKey;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class ApiKeyController extends Controller
{
    use ApiResponse;

    #[OA\Get(path: '/api/v1/integrations/api-keys', operationId: 'listApiKeys', description: 'API keys of the active tenant. Secrets are never returned.', summary: 'List API keys', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Integrations'], responses: [
        new OA\Response(response: 200, description: 'The keys', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Only owners and admins', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function index(): JsonResponse
    {
        return $this->successResponse(ApiKey::query()->latest()->get(), 'API keys retrieved');
    }

    #[OA\Post(path: '/api/v1/integrations/api-keys', operationId: 'createApiKey', description: 'Creates a key. The token is returned only by this call: it is stored as a hash.', summary: 'Create API key', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name', 'scopes'], properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 100),
        new OA\Property(property: 'scopes', type: 'array', items: new OA\Items(type: 'string', enum: ['reporting.read'])),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
    ])), tags: ['Integrations'], responses: [
        new OA\Response(response: 201, description: 'Key created, with its token', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function store(Request $request, ModuleAccessGate $moduleAccess, TenantContext $tenantContext): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => [
                'string',
                Rule::in(config('permissions.api_key_scopes', [])),
                // A key can never do more than the person who creates it.
                function (string $attribute, mixed $scope, Closure $fail) use ($request, $moduleAccess, $tenantContext): void {
                    [$module, $access] = array_pad(explode('.', (string) $scope, 2), 2, '');

                    if (! in_array($access, ['read', 'write'], true) || config("permissions.modules.{$module}") === null || ! $moduleAccess->allows($request->user(), $tenantContext->getTenantId(), $module, $access)) {
                        $fail(__('validation.in', ['attribute' => $attribute]));
                    }
                },
            ],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $secret = ApiKey::TokenPrefix.Str::random(48);

        $key = ApiKey::create([
            ...$data,
            'prefix' => substr($secret, 0, 12),
            'key_hash' => hash('sha256', $secret),
            'created_by' => $request->user()?->id,
        ]);

        return $this->successResponse([...$key->toArray(), 'token' => $secret], 'API key created', Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/integrations/api-keys/{id}/rotate', operationId: 'rotateApiKey', description: 'Replaces a key without changing its name, scopes or expiry: the old key is revoked at once and a new one is created. The new token is returned only by this call. A key can never do more than the person who rotates it, so a user who has since lost access to a scope cannot carry it over (422).', summary: 'Rotate API key', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Integrations'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 201, description: 'New key created, old key revoked', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Key not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'The key is revoked or expired, or the user no longer holds one of its scopes', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function rotate(Request $request, string $id, ModuleAccessGate $moduleAccess, TenantContext $tenantContext): JsonResponse
    {
        $old = ApiKey::findOrFail($id);

        if (! $old->isUsable()) {
            throw ValidationException::withMessages(['id' => __('sales.api_key_not_rotatable')]);
        }

        foreach ($old->scopes ?? [] as $scope) {
            [$module, $access] = array_pad(explode('.', (string) $scope, 2), 2, '');

            if (! in_array($access, ['read', 'write'], true) || config("permissions.modules.{$module}") === null || ! $moduleAccess->allows($request->user(), $tenantContext->getTenantId(), $module, $access)) {
                throw ValidationException::withMessages(['scopes' => __('sales.api_key_scope_not_held', ['scope' => $scope])]);
            }
        }

        $secret = ApiKey::TokenPrefix.Str::random(48);

        $key = $old->getConnection()->transaction(function () use ($old, $secret, $request): ApiKey {
            $old->update(['revoked_at' => now()]);

            return ApiKey::create([
                'name' => $old->name,
                'scopes' => $old->scopes,
                'expires_at' => $old->expires_at,
                'prefix' => substr($secret, 0, 12),
                'key_hash' => hash('sha256', $secret),
                'created_by' => $request->user()?->id,
            ]);
        });

        return $this->successResponse([...$key->toArray(), 'token' => $secret, 'replaces' => $old->id], 'API key rotated', Response::HTTP_CREATED);
    }

    #[OA\Delete(path: '/api/v1/integrations/api-keys/{id}', operationId: 'revokeApiKey', description: 'Revokes a key at once. The record stays for the audit trail.', summary: 'Revoke API key', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Integrations'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Key revoked', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function revoke(string $id): JsonResponse
    {
        $key = ApiKey::findOrFail($id);
        $key->update(['revoked_at' => $key->revoked_at ?? now()]);

        return $this->successResponse($key, 'API key revoked');
    }
}
