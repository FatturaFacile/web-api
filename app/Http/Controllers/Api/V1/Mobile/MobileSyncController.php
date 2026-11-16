<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Api\V1\Mobile\Concerns\ResolvesMobileContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\MobileOfflineCacheManifest;
use App\Services\Mobile\OfflineSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MobileSyncController extends Controller
{
    use ApiResponse, ResolvesMobileContext;

    public function __construct(private readonly OfflineSyncService $sync) {}

    public function entities(Request $request): JsonResponse
    {
        $allowed = array_values(array_filter(
            array_keys($this->sync->entities()),
            fn (string $e) => $this->sync->isAllowed($request->user(), $e)
        ));

        return $this->successResponse(['entities' => $allowed, 'page_size' => (int) config('mobile.sync_page_size')]);
    }

    /** GET /mobile/sync/{entity}?updated_since=ISO8601&cursor=...&limit=200 */
    public function delta(Request $request, string $entity): JsonResponse
    {
        $params = $request->validate([
            'updated_since' => ['nullable', 'date'],
            'cursor' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        if (! array_key_exists($entity, $this->sync->entities())) {
            return $this->problemDetailsResponse('Unknown Entity', "Entity '{$entity}' is not available for offline sync.", Response::HTTP_NOT_FOUND);
        }

        if (! $this->sync->isAllowed($request->user(), $entity)) {
            return $this->problemDetailsResponse('Forbidden', "You are not allowed to sync '{$entity}'.", Response::HTTP_FORBIDDEN);
        }

        $result = $this->sync->delta(
            $request->user(),
            $entity,
            $params['updated_since'] ?? null,
            $params['cursor'] ?? null,
            isset($params['limit']) ? (int) $params['limit'] : null,
        );

        // a completed full pass (no more pages) refreshes the server-side manifest
        if (! $result['has_more'] && ($device = $this->currentDevice($request)) !== null) {
            MobileOfflineCacheManifest::updateOrCreate(
                ['mobile_device_session_id' => $device->id, 'entity_name' => $entity],
                ['tenant_id' => $device->tenant_id, 'last_synced_at' => $result['server_time']]
            );
        }

        return $this->successResponse($result, 'Delta sync');
    }
}
