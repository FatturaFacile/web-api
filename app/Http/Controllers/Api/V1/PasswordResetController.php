<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\CheckPasswordResetToken;
use App\Actions\Auth\RequestPasswordReset;
use App\Actions\Auth\ResetPassword;
use App\Enums\Auth\AuthProblem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\RegistrationAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

use function Illuminate\Support\defer;

class PasswordResetController extends Controller
{
    use ApiResponse;

    #[OA\Post(
        path: '/api/v1/auth/forgot-password',
        operationId: 'forgotPassword',
        description: 'Send a reset link, invalidating the previous ones. The answer does not reveal whether the address is known.',
        summary: 'Request a password reset',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['email'], properties: [new OA\Property(property: 'email', type: 'string', format: 'email')])
        ),
        responses: [
            new OA\Response(response: 202, description: 'Request accepted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Too many attempts'),
        ]
    )]
    public function forgot(ForgotPasswordRequest $request, RequestPasswordReset $action): JsonResponse
    {
        return $this->accepted($request, $action, isResend: false);
    }

    #[OA\Post(
        path: '/api/v1/auth/resend-reset-password-email',
        operationId: 'resendResetPasswordEmail',
        description: 'Send a new reset link, invalidating the previous ones. The answer does not reveal whether the address is known.',
        summary: 'Resend the password reset email',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['email'], properties: [new OA\Property(property: 'email', type: 'string', format: 'email')])
        ),
        responses: [
            new OA\Response(response: 202, description: 'Request accepted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Too many attempts'),
        ]
    )]
    public function resend(ForgotPasswordRequest $request, RequestPasswordReset $action): JsonResponse
    {
        return $this->accepted($request, $action, isResend: true);
    }

    #[OA\Get(
        path: '/api/v1/auth/validate-reset-token',
        operationId: 'validateResetToken',
        description: 'Check a reset token without consuming it, so that mail scanners opening the link cannot use it up.',
        summary: 'Validate a password reset token',
        tags: ['Authentication'],
        parameters: [new OA\Parameter(name: 'token', in: 'query', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The token can be used', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 400, description: 'Invalid or already used token', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 410, description: 'Expired token', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 429, description: 'Too many attempts'),
        ]
    )]
    public function validateToken(Request $request, CheckPasswordResetToken $check, RegistrationAudit $audit): JsonResponse
    {
        $rawToken = $request->query('token');
        $result = $check->execute(is_string($rawToken) ? $rawToken : '');

        if ($result instanceof AuthProblem) {
            return $this->problem($result);
        }

        $audit->record(RegistrationAudit::PASSWORD_RESET_LINK_OPENED, $result->user_id);

        return $this->successResponse(message: __('password_reset.messages.token_valid'));
    }

    #[OA\Post(
        path: '/api/v1/auth/reset-password',
        operationId: 'resetPassword',
        description: 'Consume a reset token, set the new password, revoke every access token and session, and notify the user.',
        summary: 'Set a new password',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['token', 'password', 'password_confirmation'],
                properties: [
                    new OA\Property(property: 'token', type: 'string'),
                    new OA\Property(property: 'password', type: 'string', format: 'password'),
                    new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Password updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 400, description: 'Invalid or already used token', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 410, description: 'Expired token', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Too many attempts'),
        ]
    )]
    public function reset(ResetPasswordRequest $request, ResetPassword $action): JsonResponse
    {
        $problem = $action->execute($request->validated('token'), $request->validated('password'));

        return $problem === null
            ? $this->successResponse(message: __('password_reset.messages.updated'))
            : $this->problem($problem);
    }

    private function accepted(ForgotPasswordRequest $request, RequestPasswordReset $action, bool $isResend): JsonResponse
    {
        $email = $request->validated('email');

        // After the response: otherwise the extra work for a known account would reveal it through the timing.
        defer(fn () => $action->execute($email, $isResend));

        return $this->successResponse(message: __('password_reset.messages.requested'), statusCode: Response::HTTP_ACCEPTED);
    }

    private function problem(AuthProblem $problem): JsonResponse
    {
        [$key, $status] = match ($problem) {
            AuthProblem::PasswordResetTokenExpired => ['token_expired', Response::HTTP_GONE],
            AuthProblem::PasswordResetLocked => ['locked', Response::HTTP_TOO_MANY_REQUESTS],
            default => ['token_invalid', Response::HTTP_BAD_REQUEST],
        };

        return $this->problemDetailsResponse(
            title: __("password_reset.errors.{$key}_title"),
            detail: __("password_reset.errors.{$key}_detail"),
            status: $status,
            type: $problem->value,
        );
    }
}
