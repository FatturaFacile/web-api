<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmedPasswordRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\UserSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The open accesses of the signed-in user, Bearer tokens and browser sessions, and their revocation.
 */
class SessionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly UserSessions $sessions,
        private readonly RegistrationAudit $audit,
    ) {}

    #[OA\Get(
        path: '/api/v1/auth/sessions',
        operationId: 'listAuthSessions',
        description: 'Every open access of the signed-in user: Bearer tokens (kind `token`) and browser cookie sessions (kind `session`), with the current one flagged. A session id is a hash: the real id is the cookie value and is never exposed.',
        summary: 'List open sessions and tokens',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(response: 200, description: 'Open sessions and tokens', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->successResponse(data: $this->sessions->list($user, $this->currentTokenId($user), $this->currentSessionId($request)));
    }

    #[OA\Delete(
        path: '/api/v1/auth/sessions/{kind}/{id}',
        operationId: 'revokeAuthSession',
        description: 'Revokes one token or browser session of the signed-in user. Revoking the current one is a logout.',
        summary: 'Revoke a session or token',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        parameters: [
            new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['token', 'session'])),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Revoked', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'No such session of the user', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(Request $request, string $kind, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->sessions->revoke($user, $kind, $id)) {
            return $this->problemDetailsResponse(
                title: __('sessions.errors.not_found_title'),
                detail: __('sessions.errors.not_found_detail'),
                status: Response::HTTP_NOT_FOUND,
            );
        }

        $this->audit->record(RegistrationAudit::SESSION_REVOKED, (string) $user->id, ['kind' => $kind]);

        return $this->successResponse(message: __('sessions.messages.revoked'));
    }

    #[OA\Post(
        path: '/api/v1/auth/sessions/revoke-others',
        operationId: 'revokeOtherAuthSessions',
        description: 'Signs the user out everywhere else: every token and browser session but the current one is revoked. Needs the current password.',
        summary: 'Revoke all other sessions',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TwoFactorPasswordRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Counts of revoked tokens and sessions', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Wrong password', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function revokeOthers(ConfirmedPasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $revoked = $this->sessions->revokeOthers($user, $this->currentTokenId($user), $this->currentSessionId($request));

        $this->audit->record(RegistrationAudit::OTHER_SESSIONS_REVOKED, (string) $user->id, $revoked);

        return $this->successResponse(data: $revoked, message: __('sessions.messages.others_revoked'));
    }

    private function currentTokenId(User $user): ?string
    {
        return UserSessions::currentTokenId($user);
    }

    private function currentSessionId(Request $request): ?string
    {
        return $request->hasSession() ? $request->session()->getId() : null;
    }
}
