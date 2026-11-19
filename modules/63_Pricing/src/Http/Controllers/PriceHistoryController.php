<?php

declare(strict_types=1);

namespace Modules\Pricing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Pricing\Http\Resources\PricingResource;
use Modules\Pricing\Models\PriceListHistory;

/**
 * Read-only price history, newest change first. Filters: price_list_id, product_id, price_list_item_id.
 */
final class PriceHistoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PriceListHistory::class);

        $histories = PriceListHistory::query()
            ->when($request->filled('price_list_id'), static fn ($q) => $q->where('price_list_id', $request->query('price_list_id')))
            ->when($request->filled('price_list_item_id'), static fn ($q) => $q->where('price_list_item_id', $request->query('price_list_item_id')))
            ->when($request->filled('product_id'), static fn ($q) => $q->where('product_id', $request->query('product_id')))
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->paginate(max(1, min(100, (int) $request->query('per_page', 25))));

        $authors = User::query()->whereIn('id', $histories->pluck('changed_by_user_id')->filter()->unique())->pluck('name', 'id');
        $histories->getCollection()->transform(static function (PriceListHistory $history) use ($authors): PriceListHistory {
            $history->setAttribute('changed_by_name', $authors[$history->changed_by_user_id] ?? null);

            return $history;
        });

        return PricingResource::collection($histories);
    }
}
