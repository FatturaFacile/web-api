<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Translation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TranslationController extends Controller
{
    public function index(Request $request, string $locale)
    {
        $tenantId = $request->query('tenant_id') ?? $request->header('X-Tenant-ID');

        if (in_array($tenantId, ['null', 'undefined', 'default', ''], true)) {
            $tenantId = null;
        }

        $cacheKey = $tenantId ? "translations_{$tenantId}_{$locale}" : "translations_global_{$locale}";

        $translations = Cache::rememberForever($cacheKey, function () use ($locale, $tenantId) {
            $global = Translation::whereNull('tenant_id')
                ->where('locale', $locale)
                ->pluck('value', 'key');

            if (! $tenantId) {
                return $global;
            }

            $tenantCustom = Translation::where('tenant_id', $tenantId)
                ->where('locale', $locale)
                ->pluck('value', 'key');

            return $global->merge($tenantCustom);
        });

        return response()->json([
            'locale' => $locale,
            'tenant_id' => $tenantId,
            'translations' => $translations,
        ]);
    }

    public function storeOrUpdate(Request $request)
    {
        $tenantId = $request->input('tenant_id') ?? $request->header('X-Tenant-ID');
        if (in_array($tenantId, ['null', 'undefined', 'default', ''], true)) {
            $tenantId = null;
        }

        $validated = $request->validate([
            'tenant_id' => 'nullable|uuid|exists:tenants,id',
            'locale' => 'required|string|max:5',
            'key' => 'required|string',
            'value' => 'required|string',
        ]);

        $translation = Translation::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'locale' => $validated['locale'],
                'key' => $validated['key'],
            ],
            [
                'value' => $validated['value'],
            ]
        );

        if ($tenantId) {
            Cache::forget("translations_{$tenantId}_{$validated['locale']}");
        } else {
            Cache::forget("translations_global_{$validated['locale']}");
        }

        return response()->json([
            'message' => 'Translation saved successfully',
            'data' => $translation,
        ]);
    }
}
