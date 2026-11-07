<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lookups\SaveLookupTranslationRequest;
use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use App\Services\Tenancy\PublicTenantResolver;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class LookupController extends Controller
{
    /**
     * Retrieve lookup items by category code with multi-tenant and multilingual merging.
     */
    #[OA\Get(
        path: '/api/v1/lookups/{category_code}',
        operationId: 'getLookupByCategory',
        description: 'Retrieve lookup items for a category code with tenant overrides and multilingual fallback (IT/DE/FR/EN).',
        summary: 'Get lookup items by category',
        tags: ['Lookups'],
        parameters: [
            new OA\Parameter(name: 'category_code', in: 'path', required: true, description: 'Lookup category code (e.g. unit_of_measure, vat_rate)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'locale', in: 'query', required: false, description: 'Target language code (it, de, fr, en)', schema: new OA\Schema(type: 'string', enum: ['it', 'de', 'fr', 'en'], default: 'it')),
            new OA\Parameter(name: 'tenant_id', in: 'query', required: false, description: 'Tenant UUID for tenant-specific overrides', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'X-Tenant-Id', in: 'header', required: false, description: 'Tenant UUID header', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lookup items retrieved successfully',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'code', type: 'string'),
                            new OA\Property(property: 'label', type: 'string'),
                            new OA\Property(property: 'short_label', type: 'string', nullable: true),
                            new OA\Property(property: 'metadata', type: 'object'),
                            new OA\Property(property: 'is_default', type: 'boolean'),
                        ]
                    )
                )
            ),
            new OA\Response(response: 404, description: 'Lookup category not found'),
        ]
    )]
    public function getByCategory(Request $request, PublicTenantResolver $tenantResolver, string $category_code): JsonResponse
    {
        $category = LookupCategory::where('code', $category_code)->first();

        if (! $category) {
            return response()->json([
                'message' => "Lookup category '{$category_code}' not found.",
            ], Response::HTTP_NOT_FOUND);
        }

        $locale = (string) $request->query('locale', 'it');
        $tenantId = $tenantResolver->resolve($request);

        $cacheKey = $tenantId
            ? "lookups_{$category_code}_{$tenantId}_{$locale}"
            : "lookups_{$category_code}_global_{$locale}";

        $items = Cache::rememberForever($cacheKey, function () use ($category, $tenantId, $locale): array {
            // Load global active items
            $globalItems = LookupItem::where('category_id', $category->id)
                ->whereNull('tenant_id')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('code')
                ->with('translations')
                ->get();

            // Load tenant active items if tenant specified
            $tenantItems = $tenantId
                ? LookupItem::where('category_id', $category->id)
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('code')
                    ->with('translations')
                    ->get()
                : collect();

            // Merge items: tenant-specific code overrides global item with same code
            $mergedItems = [];

            foreach ($globalItems as $item) {
                $mergedItems[$item->code] = $item;
            }

            foreach ($tenantItems as $item) {
                $mergedItems[$item->code] = $item;
            }

            $results = [];

            foreach ($mergedItems as $item) {
                // Find matching translation: prioritize tenant translation, fallback to global
                $translation = null;

                if ($tenantId) {
                    $translation = $item->translations
                        ->first(fn (LookupItemTranslation $t): bool => $t->tenant_id === $tenantId && $t->locale === $locale);
                }

                if (! $translation) {
                    $translation = $item->translations
                        ->first(fn (LookupItemTranslation $t): bool => $t->tenant_id === null && $t->locale === $locale);
                }

                if (! $translation) {
                    // Fallback to Italian or first available translation
                    $translation = $item->translations
                        ->first(fn (LookupItemTranslation $t): bool => $t->locale === 'it')
                        ?? $item->translations->first();
                }

                $results[] = [
                    'id' => $item->id,
                    'code' => $item->code,
                    'label' => $translation?->label ?? $item->code,
                    'short_label' => $translation?->short_label,
                    'metadata' => $item->metadata ?? [],
                    'is_default' => (bool) $item->is_default,
                ];
            }

            return $results;
        });

        return response()->json($items);
    }

    /**
     * Store or update a lookup item translation.
     */
    #[OA\Post(
        path: '/api/v1/lookups/translations',
        operationId: 'saveLookupTranslation',
        description: 'Creates or updates a custom translation for a lookup item, optionally scoped to a tenant.',
        summary: 'Store or update lookup item translation',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Lookups'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['lookup_item_id', 'locale', 'label'],
                properties: [
                    new OA\Property(property: 'lookup_item_id', type: 'string', format: 'uuid', example: '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b'),
                    new OA\Property(property: 'locale', type: 'string', maxLength: 5, example: 'en'),
                    new OA\Property(property: 'label', type: 'string', maxLength: 255, example: 'Piece'),
                    new OA\Property(property: 'short_label', type: 'string', maxLength: 15, nullable: true, example: 'pc'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'tenant_id', type: 'string', nullable: true, description: 'Ignored: the label belongs to the active tenant. Send "global" (platform administrators only) for a global label.'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Lookup translation saved successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not an owner or administrator, or a global write by a non platform administrator'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function saveTranslation(SaveLookupTranslationRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $request->wantsGlobal() ? null : $tenantContext->getTenantId();
        $validated = $request->validated();

        $translation = LookupItemTranslation::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'lookup_item_id' => $validated['lookup_item_id'],
                'locale' => $validated['locale'],
            ],
            [
                'label' => $validated['label'],
                'short_label' => $validated['short_label'] ?? null,
                'description' => $validated['description'] ?? null,
            ]
        );

        // Invalidate relevant cache keys
        $item = LookupItem::with('category')->find($validated['lookup_item_id']);
        if ($item && $item->category) {
            $categoryCode = $item->category->code;
            $locale = $validated['locale'];

            Cache::forget("lookups_{$categoryCode}_global_{$locale}");
            if ($tenantId) {
                Cache::forget("lookups_{$categoryCode}_{$tenantId}_{$locale}");
            }
        }

        return response()->json([
            'message' => 'Lookup translation saved successfully.',
            'data' => $translation,
        ], Response::HTTP_OK);
    }
}
