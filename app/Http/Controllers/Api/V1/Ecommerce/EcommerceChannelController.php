<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Ecommerce;

use App\Actions\Ecommerce\PullEcommerceOrdersAction;
use App\Actions\Ecommerce\PushProductsToEcommerceAction;
use App\Exceptions\EcommerceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ecommerce\StoreEcommerceChannelRequest;
use App\Http\Requests\Ecommerce\SyncEcommerceChannelRequest;
use App\Http\Resources\EcommerceChannelResource;
use App\Http\Responses\ApiResponse;
use App\Jobs\PushStockToEcommerceJob;
use App\Models\EcommerceChannel;
use App\Services\Ecommerce\EcommerceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

#[OA\Tag(name: 'Ecommerce Channels', description: 'Ecommerce integrations, order pull, product catalog sync, and webhooks')]
class EcommerceChannelController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/ecommerce/channels',
        summary: 'List ecommerce channels',
        description: 'Get all configured ecommerce channels',
        tags: ['Ecommerce Channels'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Channels list retrieved'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse(EcommerceChannelResource::collection(EcommerceChannel::query()->orderBy('name')->get()), __('ecommerce.messages.channels'));
    }

    /**
     * Create a channel. The webhook secret is generated when not given and returned in this response only.
     */
    #[OA\Post(
        path: '/api/v1/ecommerce/channels',
        summary: 'Create ecommerce channel',
        description: 'Create and configure a new ecommerce store integration channel',
        tags: ['Ecommerce Channels'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'driver'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'driver', type: 'string'),
                    new OA\Property(property: 'credentials', type: 'object'),
                    new OA\Property(property: 'settings', type: 'object'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Ecommerce channel created successfully'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function store(StoreEcommerceChannelRequest $request): JsonResponse
    {
        $data = $request->validated();

        $channel = EcommerceChannel::create(array_merge($data, ['webhook_secret' => $data['webhook_secret'] ?? Str::random(40)]));

        return $this->successResponse((new EcommerceChannelResource($channel))->revealingSecret(), __('ecommerce.messages.channel_created'), Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/ecommerce/channels/{id}',
        summary: 'Get ecommerce channel',
        description: 'Retrieve details for a single ecommerce channel',
        tags: ['Ecommerce Channels'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Channel details retrieved'),
            new OA\Response(response: 404, description: 'Channel not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new EcommerceChannelResource(EcommerceChannel::findOrFail($id)), __('ecommerce.messages.channel'));
    }

    /**
     * Update a channel. Credentials and settings sent are merged into the stored ones.
     */
    #[OA\Put(
        path: '/api/v1/ecommerce/channels/{id}',
        summary: 'Update ecommerce channel',
        description: 'Update ecommerce channel configuration and credentials',
        tags: ['Ecommerce Channels'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'credentials', type: 'object'),
                    new OA\Property(property: 'settings', type: 'object'),
                    new OA\Property(property: 'is_active', type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Channel updated successfully'),
            new OA\Response(response: 404, description: 'Channel not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function update(StoreEcommerceChannelRequest $request, string $id): JsonResponse
    {
        $channel = EcommerceChannel::findOrFail($id);
        $data = $request->validated();

        if (isset($data['credentials'])) {
            $data['credentials'] = array_merge($channel->credentials ?? [], $data['credentials']);
        }

        if (isset($data['settings'])) {
            $data['settings'] = array_merge($channel->settings ?? [], $data['settings']);
        }

        $channel->update($data);

        return $this->successResponse(new EcommerceChannelResource($channel->refresh()), __('ecommerce.messages.channel_updated'));
    }

    #[OA\Delete(
        path: '/api/v1/ecommerce/channels/{id}',
        summary: 'Delete ecommerce channel',
        description: 'Delete an ecommerce channel',
        tags: ['Ecommerce Channels'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Channel deleted successfully'),
            new OA\Response(response: 404, description: 'Channel not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        EcommerceChannel::findOrFail($id)->delete();

        return $this->successResponse(null, __('ecommerce.messages.channel_deleted'));
    }

    /**
     * Check the stored credentials against the shop right now.
     */
    #[OA\Post(
        path: '/api/v1/ecommerce/channels/{id}/test-connection',
        summary: 'Test channel connection',
        description: 'Test API credentials and connectivity to remote ecommerce shop',
        tags: ['Ecommerce Channels'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Connection test completed'),
            new OA\Response(response: 404, description: 'Channel not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function testConnection(EcommerceManager $manager, string $id): JsonResponse
    {
        $channel = EcommerceChannel::findOrFail($id);

        try {
            $connected = $manager->driver($channel)->testConnection();
        } catch (EcommerceException) {
            $connected = false;
        }

        return $this->successResponse(
            ['connected' => $connected, 'driver' => $channel->driver],
            __($connected ? 'ecommerce.messages.connection_ok' : 'ecommerce.messages.connection_failed')
        );
    }

    /**
     * Run a synchronisation now: `products` publishes the catalogue, `orders` imports new orders and `stock` queues a
     * stock alignment. Orders and stock respect the channel switches `sync_orders_enabled` and `sync_stock_enabled`.
     */
    #[OA\Post(
        path: '/api/v1/ecommerce/channels/{id}/sync',
        summary: 'Synchronize channel',
        description: 'Trigger catalogue push, order pull, or stock alignment for channel',
        tags: ['Ecommerce Channels'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'filters', type: 'array', items: new OA\Items(type: 'string', enum: ['products', 'orders', 'stock'])),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Synchronization completed'),
            new OA\Response(response: 404, description: 'Channel not found'),
            new OA\Response(response: 422, description: 'Channel inactive or validation error'),
            new OA\Response(response: 502, description: 'Gateway error contacting remote platform'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function sync(
        SyncEcommerceChannelRequest $request,
        PullEcommerceOrdersAction $pullOrders,
        PushProductsToEcommerceAction $pushProducts,
        string $id,
    ): JsonResponse {
        $channel = EcommerceChannel::findOrFail($id);

        if (! $channel->is_active) {
            throw ValidationException::withMessages(['channel' => __('ecommerce.errors.channel_inactive')]);
        }

        $filters = $request->filters();
        $result = [];

        try {
            if (in_array('products', $filters, true)) {
                $result['products'] = $pushProducts->execute($channel);
            }

            if (in_array('orders', $filters, true)) {
                $result['orders'] = $channel->sync_orders_enabled ? $pullOrders->execute($channel) : 'disabled';
            }

            if (in_array('stock', $filters, true)) {
                if ($channel->sync_stock_enabled) {
                    PushStockToEcommerceJob::dispatch($channel->tenant_id, null, $channel->id);
                }

                $result['stock'] = $channel->sync_stock_enabled ? 'queued' : 'disabled';
            }
        } catch (EcommerceException $e) {
            return $this->problemDetailsResponse(
                title: __('ecommerce.problem_titles.sync'),
                detail: $e->getMessage(),
                status: Response::HTTP_BAD_GATEWAY
            );
        }

        return $this->successResponse($result, __('ecommerce.messages.sync_done'));
    }
}
