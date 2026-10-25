<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Authenticate user credentials and issue a Sanctum Bearer token.
     */
    #[OA\Post(
        path: '/api/v1/auth/login',
        operationId: 'login',
        description: 'Authenticate user credentials and issue a Sanctum Bearer token.',
        summary: 'User login',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'secret123'),
                    new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', nullable: true, example: '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Authenticated successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Invalid credentials',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
            new OA\Response(
                response: 403,
                description: 'Account disabled or invalid tenant access',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')
            ),
        ]
    )]
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'tenant_id' => ['nullable', 'uuid'],
        ]);

        /** @var User|null $user */
        $user = User::with('tenants')->where('email', $validated['email'])->first();

        if ($user === null || ! Hash::check($validated['password'], $user->password)) {
            return $this->problemDetailsResponse(
                title: 'Invalid Credentials',
                detail: 'The provided email or password does not match our records.',
                status: Response::HTTP_UNAUTHORIZED
            );
        }

        if (! $user->is_active) {
            return $this->problemDetailsResponse(
                title: 'Account Disabled',
                detail: 'Your user account has been disabled. Please contact your organization administrator.',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $tenants = $user->tenants;
        $activeTenant = null;

        if (! empty($validated['tenant_id'])) {
            $activeTenant = $tenants->firstWhere('id', $validated['tenant_id']);
            if ($activeTenant === null) {
                return $this->problemDetailsResponse(
                    title: 'Invalid Tenant Access',
                    detail: 'You are not a member of the requested tenant.',
                    status: Response::HTTP_FORBIDDEN
                );
            }
        } else {
            $activeTenant = $tenants->first();
        }

        $tokenName = 'auth-token-'.now()->timestamp;
        $token = $user->createToken($tokenName, ['*'])->plainTextToken;

        return $this->successResponse(
            data: [
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'locale' => $user->locale,
                ],
                'active_tenant' => $activeTenant ? [
                    'id' => $activeTenant->id,
                    'name' => $activeTenant->name,
                    'legal_name' => $activeTenant->legal_name,
                    'country' => $activeTenant->country,
                    'default_currency' => $activeTenant->default_currency,
                    'role' => $activeTenant->pivot?->role,
                ] : null,
                'available_tenants' => $tenants->map(fn (Tenant $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'country' => $t->country,
                    'role' => $t->pivot?->role,
                ]),
            ],
            message: 'Authenticated successfully'
        );
    }

    /**
     * Return profile of the authenticated user.
     */
    #[OA\Get(
        path: '/api/v1/auth/profile',
        operationId: 'getProfile',
        description: 'Return profile of the authenticated user.',
        summary: 'Get user profile',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Profile retrieved successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
        ]
    )]
    public function profile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->load('tenants');

        $activeTenant = $this->tenantContext->getTenant();

        return $this->successResponse(
            data: [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'locale' => $user->locale,
                'is_active' => $user->is_active,
                'active_tenant' => $activeTenant ? [
                    'id' => $activeTenant->id,
                    'name' => $activeTenant->name,
                    'country' => $activeTenant->country,
                    'role' => $user->getRoleInTenant($activeTenant->id),
                ] : null,
                'tenants' => $user->tenants->map(fn (Tenant $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'role' => $t->pivot?->role,
                ]),
            ],
            message: 'Profile retrieved successfully'
        );
    }

    /**
     * Switch active tenant context for the user session.
     */
    #[OA\Post(
        path: '/api/v1/auth/switch-tenant',
        operationId: 'switchTenant',
        description: 'Switch active tenant context for the user session.',
        summary: 'Switch tenant context',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['tenant_id'],
                properties: [
                    new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', example: '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Active organization switched successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
            new OA\Response(
                response: 403,
                description: 'Forbidden - not a member of the requested tenant',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')
            ),
        ]
    )]
    public function switchTenant(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid', 'exists:tenants,id'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! $user->belongsToTenant($validated['tenant_id'])) {
            return $this->problemDetailsResponse(
                title: 'Forbidden Tenant',
                detail: 'You do not belong to the selected tenant.',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $tenant = Tenant::findOrFail($validated['tenant_id']);
        $this->tenantContext->setTenant($tenant);

        return $this->successResponse(
            data: [
                'tenant_id' => $tenant->id,
                'name' => $tenant->name,
                'country' => $tenant->country,
                'role' => $user->getRoleInTenant($tenant->id),
            ],
            message: 'Active organization switched successfully'
        );
    }

    /**
     * Revoke current access token.
     */
    #[OA\Post(
        path: '/api/v1/auth/logout',
        operationId: 'logout',
        description: 'Revoke current access token.',
        summary: 'User logout',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successfully logged out',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
        ]
    )]
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->currentAccessToken()?->delete();

        return $this->successResponse(
            message: 'Successfully logged out'
        );
    }

    /**
     * Refresh bearer token.
     */
    #[OA\Post(
        path: '/api/v1/auth/refresh',
        operationId: 'refreshToken',
        description: 'Refresh bearer token.',
        summary: 'Refresh access token',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Token refreshed successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
        ]
    )]
    public function refresh(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->currentAccessToken()?->delete();

        $tokenName = 'auth-token-'.now()->timestamp;
        $newToken = $user->createToken($tokenName, ['*'])->plainTextToken;

        return $this->successResponse(
            data: [
                'token' => $newToken,
                'token_type' => 'Bearer',
            ],
            message: 'Token refreshed successfully'
        );
    }
}
