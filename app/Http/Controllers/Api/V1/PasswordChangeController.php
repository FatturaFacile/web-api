<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\ChangePassword;
use App\Enums\Auth\AuthProblem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Password change by the signed-in user, from the profile.
 */
class PasswordChangeController extends Controller
{
    use ApiResponse;

    #[OA\Put(
        path: '/api/v1/auth/password',
        operationId: 'changePassword',
        description: 'Changes the password of the signed-in user. Needs the current password and, when the user has two-factor authentication, a code of the app or a recovery code. On success every session and token of the user is revoked, the current one included: the client signs in again with the new password. An email reports the change. After repeated wrong answers the change is locked for a while.',
        summary: 'Change password',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['current_password', 'password', 'password_confirmation'],
            properties: [
                new OA\Property(property: 'current_password', type: 'string', format: 'password'),
                new OA\Property(property: 'password', type: 'string', format: 'password', description: 'Follows the password policy and differs from the current one'),
                new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
                new OA\Property(property: 'code', type: 'string', example: '123456', nullable: true, description: 'Required with two-factor authentication, unless recovery_code is given'),
                new OA\Property(property: 'recovery_code', type: 'string', example: 'abcde-fghjk', nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Password changed, every session revoked', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Wrong current password or code, or new password not valid', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Too many wrong attempts, change locked for a while', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function update(ChangePasswordRequest $request, ChangePassword $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $problem = $action->execute(
            $user,
            $request->validated('current_password'),
            $request->validated('password'),
            $request->validated('code'),
            $request->validated('recovery_code'),
        );

        if ($problem === AuthProblem::PasswordChangeLocked) {
            return $this->problemDetailsResponse(
                title: __('password_change.errors.locked_title'),
                detail: __('password_change.errors.locked_detail'),
                status: Response::HTTP_TOO_MANY_REQUESTS,
                type: $problem->value,
            );
        }

        // The session rows are already gone; this drops the cookie session of the current request as well.
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $this->successResponse(message: __('password_change.messages.changed'));
    }
}
