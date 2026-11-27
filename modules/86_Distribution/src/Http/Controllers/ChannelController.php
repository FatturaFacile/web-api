<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Distribution\Http\Controllers\Concerns\InteractsWithDistribution;
use Modules\Distribution\Http\Requests\DistributionFilterRequest;
use Modules\Distribution\Http\Requests\SaveChannelWarehouseRequest;
use Modules\Distribution\Http\Requests\SaveSalesChannelRequest;
use Modules\Distribution\Http\Resources\ArrayResource;
use Modules\Distribution\Http\Resources\SalesChannelResource;
use Modules\Distribution\Http\Resources\SalesChannelWarehouseResource;
use Modules\Distribution\Models\SalesChannel;
use Modules\Distribution\Models\SalesChannelWarehouse;
use Modules\Distribution\Services\ChannelAvailabilityService;
use Modules\Inventory\Models\StockItem;
use OpenApi\Attributes as OA;

/**
 * Sales channels, their warehouse allocation rules and the available-to-promise (ATP) per channel.
 */
final class ChannelController extends Controller
{
    use ApiResponse;
    use InteractsWithDistribution;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/distribution/channels', operationId: 'distributionListChannels', summary: 'List the sales channels with their rules', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Channels')])]
    public function index(DistributionFilterRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', SalesChannel::class);

        $page = SalesChannel::query()->with('rules.warehouse')
            ->when($request->input('q'), fn ($rows, $term) => $rows->where(fn ($inner) => $inner->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower((string) $term).'%'])->orWhereRaw('LOWER(code) LIKE ?', ['%'.mb_strtolower((string) $term).'%'])))
            ->orderBy('priority')
            ->orderBy('code')
            ->paginate($this->perPage(100));

        return $this->paged($page, SalesChannelResource::class);
    }

    #[OA\Post(path: '/api/v1/distribution/channels', operationId: 'distributionCreateChannel', summary: 'Create a sales channel', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 201, description: 'Channel created')])]
    public function store(SaveSalesChannelRequest $request): JsonResponse
    {
        Gate::authorize('create', SalesChannel::class);

        $channel = SalesChannel::create($request->validated() + ['priority' => 100]);

        return $this->createdResponse(new SalesChannelResource($channel->load('rules.warehouse')), $this->message('saved'));
    }

    #[OA\Put(path: '/api/v1/distribution/channels/{id}', operationId: 'distributionUpdateChannel', summary: 'Update a sales channel', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Channel updated')])]
    public function update(string $id, SaveSalesChannelRequest $request): JsonResponse
    {
        $channel = SalesChannel::query()->findOrFail($id);
        Gate::authorize('update', $channel);
        $channel->update($request->validated());

        return $this->successResponse(new SalesChannelResource($channel->refresh()->load('rules.warehouse')), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/distribution/channels/{id}', operationId: 'distributionDeleteChannel', summary: 'Delete a sales channel', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Channel deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $channel = SalesChannel::query()->findOrFail($id);
        Gate::authorize('delete', $channel);
        $channel->rules()->forceDelete();
        $channel->forceDelete();

        return $this->successResponse(null, $this->message('deleted'));
    }

    #[OA\Get(path: '/api/v1/distribution/channels/{id}/warehouses', operationId: 'distributionListChannelWarehouses', summary: 'List the warehouse rules of a channel', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Rules')])]
    public function rules(string $id): JsonResponse
    {
        $channel = SalesChannel::query()->findOrFail($id);
        Gate::authorize('view', $channel);

        return $this->successResponse(SalesChannelWarehouseResource::collection($channel->rules()->with('warehouse')->orderBy('priority_rank')->get()), $this->message('listed'));
    }

    #[OA\Post(path: '/api/v1/distribution/channels/{id}/warehouses', operationId: 'distributionCreateChannelWarehouse', summary: 'Map a warehouse to a channel', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 201, description: 'Rule created')])]
    public function storeRule(string $id, SaveChannelWarehouseRequest $request): JsonResponse
    {
        $channel = SalesChannel::query()->findOrFail($id);
        Gate::authorize('update', $channel);

        if ($channel->rules()->where('warehouse_id', $request->input('warehouse_id'))->exists()) {
            return response()->json(['type' => 'https://httpstatuses.com/422', 'title' => 'Duplicate rule', 'status' => 422, 'detail' => $this->message('channel_warehouse_exists'), 'message' => $this->message('channel_warehouse_exists'), 'code' => 'rule_exists'], 422, ['Content-Type' => 'application/problem+json']);
        }

        $rule = $channel->rules()->create($request->validated() + ['priority_rank' => 100, 'fixed_reserve_qty' => 0, 'safety_buffer_qty' => 0]);

        return $this->createdResponse(new SalesChannelWarehouseResource($rule->load('warehouse')), $this->message('saved'));
    }

    #[OA\Put(path: '/api/v1/distribution/channel-warehouses/{id}', operationId: 'distributionUpdateChannelWarehouse', summary: 'Update the allocation rule of a channel warehouse', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Rule updated')])]
    public function updateRule(string $id, SaveChannelWarehouseRequest $request): JsonResponse
    {
        $rule = SalesChannelWarehouse::query()->findOrFail($id);
        Gate::authorize('update', $rule);
        $rule->update($request->validated());

        return $this->successResponse(new SalesChannelWarehouseResource($rule->refresh()->load('warehouse')), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/distribution/channel-warehouses/{id}', operationId: 'distributionDeleteChannelWarehouse', summary: 'Remove a warehouse from a channel', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Rule deleted')])]
    public function destroyRule(string $id): JsonResponse
    {
        $rule = SalesChannelWarehouse::query()->findOrFail($id);
        Gate::authorize('delete', $rule);
        $rule->forceDelete();

        return $this->successResponse(null, $this->message('deleted'));
    }

    #[OA\Get(path: '/api/v1/distribution/atp', operationId: 'distributionAtp', summary: 'Available-to-promise of an item per channel and warehouse', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'ATP breakdown')])]
    public function atp(DistributionFilterRequest $request, ChannelAvailabilityService $availability): JsonResponse
    {
        Gate::authorize('viewAny', SalesChannel::class);
        abort_unless($request->filled('stock_item_id'), 422, 'stock_item_id is required');

        $item = StockItem::query()->findOrFail((string) $request->input('stock_item_id'));

        return $this->successResponse(new ArrayResource($availability->forItem($item, $request->input('sales_channel_id'))), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/distribution/atp/matrix', operationId: 'distributionAtpMatrix', summary: 'ATP of the items across all channels', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Channels and one row per item')])]
    public function matrix(DistributionFilterRequest $request, ChannelAvailabilityService $availability): JsonResponse
    {
        Gate::authorize('viewAny', SalesChannel::class);

        $perPage = $this->perPage(25);
        $matrix = $availability->matrix((int) $request->input('page', 1), $perPage);

        return $this->successResponse(new ArrayResource(['channels' => $matrix['channels'], 'rows' => $matrix['rows']]), $this->message('loaded'), 200, [
            'current_page' => (int) $request->input('page', 1), 'per_page' => $perPage, 'total' => $matrix['total'], 'last_page' => max(1, (int) ceil($matrix['total'] / $perPage)),
        ]);
    }
}
