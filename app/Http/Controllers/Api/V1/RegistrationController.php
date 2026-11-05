<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\RegisterUser;
use App\Actions\Auth\ResendVerificationEmail;
use App\Actions\Auth\VerifyEmail;
use App\Enums\Auth\AuthProblem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResendVerificationRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\TaxIdentifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class RegistrationController extends Controller
{
    use ApiResponse;

    #[OA\Post(
        path: '/api/v1/auth/register',
        operationId: 'register',
        description: 'Create an account. The answer is the same whether or not the email address or the company is already known.',
        summary: 'Self-registration',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['first_name', 'last_name', 'email', 'password', 'password_confirmation', 'company_name', 'country', 'tax_id', 'terms_accepted', 'privacy_accepted'],
                properties: [
                    new OA\Property(property: 'first_name', type: 'string', maxLength: 100),
                    new OA\Property(property: 'last_name', type: 'string', maxLength: 100),
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'password', type: 'string', format: 'password'),
                    new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
                    new OA\Property(property: 'company_name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'country', type: 'string', enum: ['IT', 'CH']),
                    new OA\Property(property: 'tax_id', description: 'Italian VAT number or Swiss UID', type: 'string'),
                    new OA\Property(property: 'terms_accepted', type: 'boolean'),
                    new OA\Property(property: 'privacy_accepted', type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 202, description: 'Registration accepted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Too many attempts'),
        ]
    )]
    public function register(RegisterRequest $request, RegisterUser $registration): JsonResponse
    {
        $validated = $request->validated();

        $registration->execute([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'company_name' => $validated['company_name'],
            'country' => $validated['country'],
            'tax_id' => (string) TaxIdentifier::normalize($validated['tax_id'], $validated['country']),
        ]);

        return $this->successResponse(message: __('registration.messages.registered'), statusCode: Response::HTTP_ACCEPTED);
    }

    #[OA\Get(
        path: '/api/v1/auth/verify-email',
        operationId: 'verifyEmail',
        description: 'Consume a verification token. The account still needs the approval of a platform administrator.',
        summary: 'Confirm an email address',
        tags: ['Authentication'],
        parameters: [new OA\Parameter(name: 'token', in: 'query', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Email address confirmed', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 400, description: 'Invalid or already used token', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 410, description: 'Expired token', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 429, description: 'Too many attempts'),
        ]
    )]
    public function verify(Request $request, VerifyEmail $verification): JsonResponse
    {
        $problem = $verification->execute((string) $request->query('token'));

        return match ($problem) {
            null => $this->successResponse(message: __('registration.messages.verified')),
            AuthProblem::VerificationTokenExpired => $this->problemDetailsResponse(
                title: __('registration.errors.token_expired_title'),
                detail: __('registration.errors.token_expired_detail'),
                status: Response::HTTP_GONE,
                type: $problem->value,
            ),
            default => $this->problemDetailsResponse(
                title: __('registration.errors.token_invalid_title'),
                detail: __('registration.errors.token_invalid_detail'),
                status: Response::HTTP_BAD_REQUEST,
                type: $problem->value,
            ),
        };
    }

    #[OA\Post(
        path: '/api/v1/auth/resend-verification-email',
        operationId: 'resendVerificationEmail',
        description: 'Send a new verification link, invalidating the previous one. The answer does not reveal whether the address is known.',
        summary: 'Resend the verification email',
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
    public function resend(ResendVerificationRequest $request, ResendVerificationEmail $resend): JsonResponse
    {
        $resend->execute($request->validated('email'));

        return $this->successResponse(message: __('registration.messages.resent'), statusCode: Response::HTTP_ACCEPTED);
    }
}
