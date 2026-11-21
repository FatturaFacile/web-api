<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\LoginResponder;
use App\Services\Auth\Security\TenantSecurityPolicies;
use App\Services\Auth\TokenMode;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Platform\Identity\Application\Passkeys\PasskeyException;
use Modules\Platform\Identity\Application\Passkeys\PasskeyService;
use Modules\Platform\Identity\Domain\Events\LoginSucceeded;
use Modules\Platform\Identity\Http\Requests\FinishPasskeyRegistrationRequest;
use Modules\Platform\Identity\Http\Requests\PasskeyLoginRequest;
use Modules\Platform\Identity\Infrastructure\Models\WebAuthnCredential;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passkeys: registration, listing and revocation for the signed-in user, and the public login ceremony. A passkey is
 * verified with user verification (biometric or PIN), so it is a complete two-factor sign-in on its own.
 */
final class PasskeyController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PasskeyService $passkeys,
        private readonly TenantContext $tenantContext,
        private readonly LoginResponder $responder,
        private readonly TenantSecurityPolicies $policies,
        private readonly TokenMode $tokenModes,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->successResponse($this->passkeys->list($user)->map(fn (WebAuthnCredential $c): array => $this->present($c))->values());
    }

    public function registrationOptions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->successResponse($this->passkeys->beginRegistration($user));
    }

    public function register(FinishPasskeyRegistrationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $credential = $this->passkeys->finishRegistration($user, $this->tenantContext->getTenantId(), (string) $request->validated('ceremony_id'), (string) $request->validated('name'), (array) $request->input('credential'));
        } catch (PasskeyException $e) {
            return $this->problem($e);
        }

        return $this->successResponse($this->present($credential), 'Passkey registered', Response::HTTP_CREATED);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->passkeys->revoke($user, $id)
            ? $this->successResponse(null, 'Passkey revoked')
            : $this->problemDetailsResponse('Not Found', 'Passkey not found.', Response::HTTP_NOT_FOUND);
    }

    public function loginOptions(): JsonResponse
    {
        return $this->successResponse($this->passkeys->beginLogin());
    }

    public function login(PasskeyLoginRequest $request): JsonResponse
    {
        $tokenMode = $this->tokenModes->resolve($request);

        if (($blocked = $this->responder->cannotOpenAccess($request, $tokenMode)) !== null) {
            return $blocked;
        }

        try {
            $user = $this->passkeys->finishLogin((string) $request->validated('ceremony_id'), (array) $request->input('credential'));
        } catch (PasskeyException $e) {
            return $this->problem($e);
        }

        $tenants = $user->tenants()->get();
        $activeTenant = $request->validated('tenant_id') ? $tenants->firstWhere('id', $request->validated('tenant_id')) : $tenants->first();

        if (! $user->is_active || $user->email_verified_at === null || $tenants->isEmpty() || ($request->validated('tenant_id') && $activeTenant === null)) {
            return $this->problemDetailsResponse('Access Denied', 'This account cannot sign in.', Response::HTTP_FORBIDDEN);
        }

        if (! $user->is_platform_admin && $this->policies->forTenant($activeTenant)->ssoOnly) {
            return $this->problemDetailsResponse('SSO Required', 'This company requires single sign-on.', Response::HTTP_FORBIDDEN);
        }

        event(new LoginSucceeded((string) $activeTenant->id, (string) $user->id, 'passkey'));

        return $this->responder->respond($request, $user, $activeTenant, $tenants, 'passkey', $tokenMode);
    }

    private function problem(PasskeyException $e): JsonResponse
    {
        $status = $e->reason === PasskeyException::EXPIRED ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_UNAUTHORIZED;

        return $this->problemDetailsResponse('Passkey Rejected', $e->reason, $status, 'urn:fatturafacile:passkey:'.$e->reason);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(WebAuthnCredential $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'aaguid' => $c->aaguid,
            'created_at' => $c->created_at?->toIso8601String(),
            'last_used_at' => $c->last_used_at?->toIso8601String(),
        ];
    }
}
