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
use Symfony\Component\HttpFoundation\Response;

class EcommerceChannelController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        return $this->successResponse(EcommerceChannelResource::collection(EcommerceChannel::query()->orderBy('name')->get()), __('ecommerce.messages.channels'));
    }

    /**
     * Create a channel. The webhook secret is generated when not given and returned in this response only.
     */
    public function store(StoreEcommerceChannelRequest $request): JsonResponse
    {
        $data = $request->validated();

        $channel = EcommerceChannel::create(array_merge($data, ['webhook_secret' => $data['webhook_secret'] ?? Str::random(40)]));

        return $this->successResponse((new EcommerceChannelResource($channel))->revealingSecret(), __('ecommerce.messages.channel_created'), Response::HTTP_CREATED);
    }

    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new EcommerceChannelResource(EcommerceChannel::findOrFail($id)), __('ecommerce.messages.channel'));
    }

    /**
     * Update a channel. Credentials and settings sent are merged into the stored ones.
     */
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

    public function destroy(string $id): JsonResponse
    {
        EcommerceChannel::findOrFail($id)->delete();

        return $this->successResponse(null, __('ecommerce.messages.channel_deleted'));
    }

    /**
     * Check the stored credentials against the shop right now.
     */
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
