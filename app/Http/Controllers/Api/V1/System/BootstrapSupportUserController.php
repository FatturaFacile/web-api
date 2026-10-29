<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\System;

use App\Actions\Auth\BootstrapSupportUser;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Operational endpoint to ensure the default support user exists.
 */
class BootstrapSupportUserController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BootstrapSupportUser $bootstrapSupportUser,
    ) {}

    #[OA\Get(
        path: '/api/v1/system/bootstrap-support',
        operationId: 'bootstrapSupportUser',
        description: 'Verify if the default support user exists. If missing, creates the account and dispatches credentials via email.',
        summary: 'Bootstrap support account',
        tags: ['System', 'Bootstrap'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Support user already present',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 201,
                description: 'Support user successfully created and email dispatched',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 500,
                description: 'Bootstrap failed due to server or mail transport error',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
        ]
    )]
    public function __invoke(): JsonResponse
    {
        if (app()->isProduction()) {
            $secret = config('services.bootstrap.secret', env('BOOTSTRAP_SUPPORT_SECRET'));
            $header = request()->header('X-Bootstrap-Secret') ?? request()->query('secret');

            if (empty($secret) || ! is_string($secret) || ! hash_equals($secret, (string) $header)) {
                return $this->problemDetailsResponse(
                    title: 'Forbidden',
                    detail: 'Support bootstrap is restricted in production environments.',
                    status: Response::HTTP_FORBIDDEN,
                );
            }
        }

        try {
            $result = $this->bootstrapSupportUser->execute();

            return $this->successResponse(
                data: [
                    'created' => $result->created,
                    'email' => $result->user->email,
                    'email_dispatched' => $result->emailDispatched,
                    'user' => [
                        'id' => $result->user->id,
                        'name' => $result->user->name,
                        'email' => $result->user->email,
                        'is_active' => $result->user->is_active,
                    ],
                ],
                message: $result->message,
                statusCode: $result->created ? Response::HTTP_CREATED : Response::HTTP_OK,
            );
        } catch (Throwable) {
            return $this->problemDetailsResponse(
                title: 'Support User Bootstrap Failed',
                detail: 'An error occurred while bootstrapping the support user. Please review system logs.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
