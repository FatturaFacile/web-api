<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\RecurringContracts;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecurringContracts\StorePriceIndexRateRequest;
use App\Http\Resources\RecurringContracts\PriceIndexRateResource;
use App\Models\RecurringContracts\PriceIndexRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PriceIndexRateController extends Controller
{
    /**
     * Display a listing of price index rates.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PriceIndexRate::query();

        if ($request->filled('index_type')) {
            $query->where('index_type', $request->query('index_type'));
        }

        if ($request->filled('period')) {
            $query->where('period', $request->query('period'));
        }

        $rates = $query->orderByDesc('period')->paginate($request->integer('per_page', 24));

        return PriceIndexRateResource::collection($rates);
    }

    /**
     * Store or update a price index rate (upsert).
     */
    public function store(StorePriceIndexRateRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $tenantId = auth()->user()?->current_tenant_id ? (string) auth()->user()->current_tenant_id : (string) $request->header('X-Tenant-Id');

        $rate = PriceIndexRate::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'index_type' => $validated['index_type'],
                'period' => $validated['period'],
            ],
            [
                'index_value' => $validated['index_value'],
                'published_at' => $validated['published_at'] ?? null,
                'source_reference' => $validated['source_reference'] ?? null,
            ]
        );

        return (new PriceIndexRateResource($rate))
            ->response()
            ->setStatusCode(200);
    }
}
