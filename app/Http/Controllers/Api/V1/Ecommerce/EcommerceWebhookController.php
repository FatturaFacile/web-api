<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Ecommerce;

use App\Actions\Ecommerce\ProcessEcommerceWebhook;
use App\Exceptions\EcommerceException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\EcommerceChannel;
use App\Services\Ecommerce\EcommerceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class EcommerceWebhookController extends Controller
{
    use ApiResponse;

    /**
     * Public webhook of a channel (no session): the channel is found by its UUID and the delivery is only processed
     * when the platform signature matches the channel secret.
     */
    #[OA\Post(
        path: '/api/v1/webhooks/ecommerce/{channelUuid}',
        operationId: 'ecommerceWebhook',
        description: 'Public webhook of an ecommerce channel: the channel is found by its UUID and the delivery is processed when the platform signature matches the channel secret.',
        summary: 'Receive ecommerce platform webhook',
        tags: ['Webhooks', 'Ecommerce'],
        parameters: [
            new OA\Parameter(
                name: 'channelUuid',
                in: 'path',
                required: true,
                description: 'Unique UUID of the ecommerce channel',
                schema: new OA\Schema(type: 'string', format: 'uuid', example: '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d')
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Webhook event payload from ecommerce platform (WooCommerce, Shopify, PrestaShop)',
            content: new OA\JsonContent
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Webhook processed successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Invalid signature',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
            new OA\Response(
                response: 404,
                description: 'Channel not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
            new OA\Response(
                response: 502,
                description: 'Bad gateway during webhook processing',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
        ]
    )]
    public function __invoke(Request $request, EcommerceManager $manager, ProcessEcommerceWebhook $process, string $channelUuid): JsonResponse
    {
        $channel = Str::isUuid($channelUuid)
            ? EcommerceChannel::withoutGlobalScopes()->where('is_active', true)->find($channelUuid)
            : null;

        if ($channel === null) {
            return $this->problemDetailsResponse(__('ecommerce.problem_titles.webhook'), __('ecommerce.errors.unknown_channel'), Response::HTTP_NOT_FOUND);
        }

        try {
            if (! $manager->driver($channel)->verifyWebhookSignature($request)) {
                return $this->problemDetailsResponse(__('ecommerce.problem_titles.webhook'), __('ecommerce.errors.invalid_signature'), Response::HTTP_UNAUTHORIZED);
            }

            return $this->successResponse($process->execute($channel, $request), __('ecommerce.messages.webhook_processed'));
        } catch (EcommerceException $e) {
            return $this->problemDetailsResponse(__('ecommerce.problem_titles.webhook'), $e->getMessage(), Response::HTTP_BAD_GATEWAY);
        }
    }
}
