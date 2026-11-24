<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sdi\ProcessSdiNotification;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sdi\SdiNotificationRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class SdiWebhookController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProcessSdiNotification $processNotification) {}

    /**
     * Receive an SDI receipt (delivery, rejection, ...) from the provider gateway.
     */
    #[OA\Post(
        path: '/api/v1/webhooks/sdi',
        operationId: 'sdiWebhook',
        description: 'Receive an SDI receipt (delivery, rejection, ...) from the provider gateway.',
        summary: 'Receive SDI provider notification',
        tags: ['Webhooks', 'SDI'],
        parameters: [
            new OA\Parameter(
                name: 'X-Webhook-Secret',
                in: 'header',
                required: true,
                description: 'Shared webhook secret for provider authentication',
                schema: new OA\Schema(type: 'string')
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['provider', 'uuid', 'event_type'],
                properties: [
                    new OA\Property(property: 'provider', type: 'string', maxLength: 30, example: 'aruba'),
                    new OA\Property(property: 'uuid', type: 'string', maxLength: 64, example: 'sdi-notif-12345'),
                    new OA\Property(property: 'event_type', type: 'string', maxLength: 20, example: 'CONSEGNA'),
                    new OA\Property(property: 'message', type: 'string', maxLength: 1000, nullable: true, example: 'Fattura recapitata al destinatario'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Notification received successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Invalid webhook secret',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')
            ),
        ]
    )]
    public function __invoke(SdiNotificationRequest $request): JsonResponse
    {
        $log = $this->processNotification->execute($request->validated());

        return $this->successResponse(
            ['status' => $log->status, 'invoice_id' => $log->invoice_id],
            __('sdi.messages.notification_received')
        );
    }
}
