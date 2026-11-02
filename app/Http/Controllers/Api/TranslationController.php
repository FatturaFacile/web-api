<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Translations\StoreTranslationRequest;
use App\Models\Translation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class TranslationController extends Controller
{
    /**
     * Retrieve translation strings for a locale, merging global defaults with tenant customizations.
     */
    #[OA\Get(
        path: '/api/v1/translations/{locale}',
        operationId: 'getTranslationsByLocale',
        description: 'Retrieve merged global and tenant UI translations for a given locale.',
        summary: 'Get localized UI translations',
        tags: ['Translations'],
        parameters: [
            new OA\Parameter(name: 'locale', in: 'path', required: true, description: 'Locale identifier (it, de, fr, en)', schema: new OA\Schema(type: 'string', enum: ['it', 'de', 'fr', 'en'])),
            new OA\Parameter(name: 'tenant_id', in: 'query', required: false, description: 'Tenant UUID for tenant-specific translations', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'X-Tenant-Id', in: 'header', required: false, description: 'Tenant UUID header', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Translations retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'locale', type: 'string', example: 'en'),
                        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', nullable: true),
                        new OA\Property(property: 'translations', type: 'object'),
                    ]
                )
            ),
        ]
    )]
    public function index(Request $request, string $locale): JsonResponse
    {
        if ($request->has('tenant_id')) {
            $tenantId = $request->input('tenant_id');
        } else {
            $tenantId = $request->header('X-Tenant-Id') ?? $request->header('X-Tenant-ID');
        }

        if (is_string($tenantId) && in_array(strtolower(trim($tenantId)), ['null', 'undefined', 'default', ''], true)) {
            $tenantId = null;
        }

        $cacheKey = $tenantId ? "translations_{$tenantId}_{$locale}" : "translations_global_{$locale}";

        $rawTranslations = Cache::rememberForever($cacheKey, function () use ($locale, $tenantId): array {
            $records = Translation::forTenant($tenantId)
                ->where('locale', $locale)
                ->orderByRaw('CASE WHEN tenant_id IS NULL THEN 0 ELSE 1 END ASC')
                ->get();

            $merged = [];
            foreach ($records as $record) {
                $merged[$record->key] = (string) $record->value;
            }

            return $merged;
        });

        if ($rawTranslations instanceof Collection) {
            $rawTranslations = $rawTranslations->toArray();
        } elseif (is_string($rawTranslations)) {
            $decoded = json_decode($rawTranslations, true);
            $rawTranslations = is_array($decoded) ? $decoded : [];
        } elseif (! is_array($rawTranslations)) {
            Cache::forget($cacheKey);

            $records = Translation::forTenant($tenantId)
                ->where('locale', $locale)
                ->orderByRaw('CASE WHEN tenant_id IS NULL THEN 0 ELSE 1 END ASC')
                ->get();

            $rawTranslations = [];
            foreach ($records as $record) {
                $rawTranslations[$record->key] = (string) $record->value;
            }

            Cache::forever($cacheKey, $rawTranslations);
        }

        $translations = [];
        foreach ($rawTranslations as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $translations[$key] = (string) $value;
            }
        }

        return response()->json([
            'locale' => $locale,
            'tenant_id' => $tenantId,
            'translations' => (object) $translations,
        ]);
    }

    /**
     * Store or update a translation string.
     */
    #[OA\Post(
        path: '/api/v1/translations',
        operationId: 'saveTranslationString',
        description: 'Create or update a global or tenant-specific translation value.',
        summary: 'Store or update translation key',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Translations'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['locale', 'key', 'value'],
                properties: [
                    new OA\Property(property: 'locale', type: 'string', maxLength: 5, example: 'en'),
                    new OA\Property(property: 'key', type: 'string', maxLength: 255, example: 'buttons.save'),
                    new OA\Property(property: 'value', type: 'string', example: 'Save Changes'),
                    new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Translation saved successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function storeOrUpdate(StoreTranslationRequest $request): JsonResponse
    {
        if ($request->has('tenant_id')) {
            $tenantId = $request->input('tenant_id');
        } else {
            $tenantId = $request->header('X-Tenant-Id') ?? $request->header('X-Tenant-ID');
        }

        if (is_string($tenantId) && in_array(strtolower(trim($tenantId)), ['null', 'undefined', 'default', ''], true)) {
            $tenantId = null;
        }

        $validated = $request->validated();

        if (array_key_exists('tenant_id', $validated)) {
            $tenantVal = $validated['tenant_id'];
            if (is_string($tenantVal) && in_array(strtolower(trim($tenantVal)), ['null', 'undefined', 'default', ''], true)) {
                $tenantId = null;
            } elseif ($tenantVal === null) {
                $tenantId = null;
            } elseif (! empty($tenantVal)) {
                $tenantId = $tenantVal;
            }
        }

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
        ], Response::HTTP_OK);
    }
}
