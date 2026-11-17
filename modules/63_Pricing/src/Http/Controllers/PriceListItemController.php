<?php

declare(strict_types=1);

namespace Modules\Pricing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use App\Models\PriceListItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Pricing\Http\Requests\BulkPriceListItemsRequest;
use Modules\Pricing\Http\Requests\PriceListItemRequest;
use Modules\Pricing\Http\Resources\PricingResource;
use Modules\Pricing\Services\PriceHistoryRecorder;

/**
 * The items (tiers and cascades) of a price list, with a bulk import/update. Every change lands in the price history.
 */
final class PriceListItemController extends Controller
{
    public function index(Request $request, string $listId): AnonymousResourceCollection
    {
        $list = PriceList::query()->findOrFail($listId);

        /** @var Builder<PriceListItem> $query */
        $query = $list->items()->getQuery();

        return PricingResource::collection(
            $query->when($request->filled('product_id'), static fn (Builder $q) => $q->where('product_id', $request->query('product_id')))
                ->orderBy('product_id')->orderBy('min_quantity')
                ->paginate(max(1, min(200, (int) $request->query('per_page', 50)))),
        );
    }

    public function store(PriceListItemRequest $request, string $listId): JsonResponse
    {
        $list = PriceList::query()->findOrFail($listId);
        $data = $request->validated();
        $reason = $data['reason'] ?? null;
        unset($data['reason']);

        $item = app(PriceHistoryRecorder::class)->withReason($reason, fn (): PriceListItem => $list->items()->create($data));

        return (new PricingResource($item->refresh()))->response()->setStatusCode(201);
    }

    public function update(PriceListItemRequest $request, string $listId, string $id): PricingResource
    {
        $item = PriceList::query()->findOrFail($listId)->items()->findOrFail($id);
        $data = $request->validated();
        $reason = $data['reason'] ?? null;
        unset($data['reason']);

        app(PriceHistoryRecorder::class)->withReason($reason, static fn (): bool => $item->update($data));

        return new PricingResource($item->refresh());
    }

    public function destroy(string $listId, string $id): Response
    {
        $item = PriceList::query()->findOrFail($listId)->items()->findOrFail($id);
        app(PriceHistoryRecorder::class)->withReason('deleted', static fn () => $item->delete());

        return response()->noContent();
    }

    /**
     * Create or update items by (product, minimum quantity, validity start) in one transaction.
     */
    public function bulk(BulkPriceListItemsRequest $request, string $listId): JsonResponse
    {
        $list = PriceList::query()->findOrFail($listId);
        $data = $request->validated();
        $recorder = app(PriceHistoryRecorder::class);
        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($list, $data, $recorder, &$created, &$updated): void {
            foreach ($data['items'] as $row) {
                $existing = $list->items()
                    ->where('product_id', $row['product_id'])
                    ->where('min_quantity', $row['min_quantity'] ?? 1)
                    ->when(isset($row['valid_from']), static fn ($q) => $q->whereDate('valid_from', $row['valid_from']), static fn ($q) => $q->whereNull('valid_from'))
                    ->first();

                $recorder->withReason($data['reason'] ?? 'bulk update', function () use ($list, $row, $existing, &$created, &$updated): void {
                    if ($existing === null) {
                        $list->items()->create($row);
                        $created++;
                    } else {
                        $existing->update($row);
                        $updated++;
                    }
                });
            }
        });

        return response()->json(['data' => ['created' => $created, 'updated' => $updated]]);
    }
}
