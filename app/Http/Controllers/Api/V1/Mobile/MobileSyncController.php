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
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class MobileSyncController extends Controller
{
    use ApiResponse, ResolvesMobileContext;

    public function __construct(private readonly OfflineSyncService $sync) {}

    #[OA\Get(
        path: '/api/v1/mobile/sync',
        operationId: 'listMobileSyncEntities',
        description: 'The entities the user may sync offline and the server page size.',
        summary: 'List syncable entities',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        responses: [
            new OA\Response(response: 200, description: 'Allowed entities', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'entities', type: 'array', items: new OA\Items(type: 'string')), new OA\Property(property: 'page_size', type: 'integer')]), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function entities(Request $request): JsonResponse
    {
        $allowed = array_values(array_filter(
            array_keys($this->sync->entities()),
            fn (string $e) => $this->sync->isAllowed($request->user(), $e)
        ));

        return $this->successResponse(['entities' => $allowed, 'page_size' => (int) config('mobile.sync_page_size')]);
    }

    /** GET /mobile/sync/{entity}?updated_since=ISO8601&cursor=...&limit=200 */
    #[OA\Get(
        path: '/api/v1/mobile/sync/{entity}',
        operationId: 'getMobileSyncDelta',
        description: 'Records of an entity changed since the given instant, cursor-paginated. A completed full pass refreshes the server-side manifest of the device.',
        summary: 'Offline delta sync',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        parameters: [new OA\Parameter(name: 'entity', in: 'path', required: true, description: 'Entity name as returned by the entities list', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'updated_since', in: 'query', required: false, description: 'Only records updated since this instant', schema: new OA\Schema(type: 'string', format: 'date-time')), new OA\Parameter(name: 'cursor', in: 'query', required: false, description: 'Cursor returned by the previous page', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Page size', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 500))],
        responses: [
            new OA\Response(response: 200, description: 'Delta page', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
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
