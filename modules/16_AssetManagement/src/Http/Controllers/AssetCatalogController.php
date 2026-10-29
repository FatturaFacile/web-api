<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\AssetManagement\Http\Resources\AssetCategoryResource;
use Modules\AssetManagement\Http\Resources\AssetLocationResource;
use Modules\AssetManagement\Models\AssetCategory;
use Modules\AssetManagement\Models\AssetClass;
use Modules\AssetManagement\Models\AssetLocation;

final class AssetCatalogController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    public function categories(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $categories = AssetCategory::where('tenant_id', $tenant->id)->get();

        return response()->json(['data' => AssetCategoryResource::collection($categories)]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();

        $category = AssetCategory::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'parent_id' => $request->input('parent_id'),
            'code' => $request->input('code'),
            'name' => $request->input('name'),
            'asset_type' => $request->input('asset_type', 'tangible'),
            'depreciation_method' => $request->input('depreciation_method', 'straight_line'),
            'useful_life_years' => (int) $request->input('useful_life_years', 5),
            'useful_life_months' => ((int) $request->input('useful_life_years', 5)) * 12,
            'statutory_rate_percent' => (float) $request->input('statutory_rate_percent', 20.0),
            'salvage_value_percent' => (float) $request->input('salvage_value_percent', 0.0),
            'is_depreciable' => (bool) $request->input('is_depreciable', true),
            'is_active' => true,
        ]);

        return response()->json(['data' => new AssetCategoryResource($category)], 201);
    }

    public function classes(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $classes = AssetClass::where('tenant_id', $tenant->id)->get();

        return response()->json(['data' => $classes]);
    }

    public function locations(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $locations = AssetLocation::where('tenant_id', $tenant->id)->get();

        return response()->json(['data' => AssetLocationResource::collection($locations)]);
    }

    public function storeLocation(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();

        $location = AssetLocation::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'parent_id' => $request->input('parent_id'),
            'code' => $request->input('code'),
            'name' => $request->input('name'),
            'location_type' => $request->input('location_type', 'facility'),
            'address' => $request->input('address'),
            'city' => $request->input('city'),
            'country' => $request->input('country', 'IT'),
            'is_active' => true,
        ]);

        return response()->json(['data' => new AssetLocationResource($location)], 201);
    }
}
