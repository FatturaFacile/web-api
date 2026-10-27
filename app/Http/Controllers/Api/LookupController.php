<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class LookupController extends Controller
{
    /**
     * Retrieve lookup items by category code with multi-tenant and multilingual merging.
     */
    public function getByCategory(Request $request, string $category_code): JsonResponse
    {
        $category = LookupCategory::where('code', $category_code)->first();

        if (! $category) {
            return response()->json([
                'message' => "Lookup category '{$category_code}' not found.",
            ], Response::HTTP_NOT_FOUND);
        }

        $locale = (string) $request->query('locale', 'it');
        $tenantId = $request->query('tenant_id') ?? $request->header('X-Tenant-ID');

        if (in_array($tenantId, ['null', 'undefined', 'default', ''], true)) {
            $tenantId = null;
        }

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
    public function saveTranslation(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id') ?? $request->header('X-Tenant-ID');
        if (in_array($tenantId, ['null', 'undefined', 'default', ''], true)) {
            $tenantId = null;
        }

        $validated = $request->validate([
            'lookup_item_id' => ['required', 'uuid', 'exists:lookup_items,id'],
            'locale' => ['required', 'string', 'max:5'],
            'label' => ['required', 'string', 'max:255'],
            'short_label' => ['nullable', 'string', 'max:15'],
            'description' => ['nullable', 'string'],
            'tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
        ]);

        if (array_key_exists('tenant_id', $validated) && ! empty($validated['tenant_id'])) {
            $tenantId = $validated['tenant_id'];
        }

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
