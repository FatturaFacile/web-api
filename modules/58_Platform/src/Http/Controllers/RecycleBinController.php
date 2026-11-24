<?php

declare(strict_types=1);

namespace Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Platform\Services\RecycleBinRegistry;
use OpenApi\Attributes as OA;

/**
 * The recycle bin: soft-deleted records of an allowlisted set of types (config platform.recycle_bin), restored or destroyed
 * for good by owners and administrators (module "platform"). Every restore and destruction is written to the audit ledger.
 */
final class RecycleBinController extends Controller
{
    public function __construct(private readonly RecycleBinRegistry $registry, private readonly AuditLedgerInterface $audit) {}

    #[OA\Get(path: '/api/v1/platform/recycle-bin/types', operationId: 'listRecycleBinTypes', description: 'The record types the recycle bin handles, with how many deleted records each holds and whether they can be destroyed for good.', summary: 'List recycle bin types', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Platform'], responses: [
        new OA\Response(response: 200, description: 'Types', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/RecycleBinType'))])),
        new OA\Response(ref: '#/components/responses/PlatformUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlatformForbidden', response: 403),
    ])]
    public function types(): JsonResponse
    {
        $types = array_map(fn (string $type): array => [
            'type' => $type,
            'count' => $this->registry->trashed($type)->count(),
            'purgeable' => $this->registry->isPurgeable($type),
        ], $this->registry->types());

        return response()->json(['data' => $types]);
    }

    #[OA\Get(path: '/api/v1/platform/recycle-bin', operationId: 'listRecycleBin', description: 'Soft-deleted records of one allowlisted type, most recently deleted first.', summary: 'List deleted records', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Platform'], parameters: [
        new OA\Parameter(name: 'type', in: 'query', required: true, schema: new OA\Schema(type: 'string', example: 'contacts')),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
    ], responses: [
        new OA\Response(response: 200, description: 'Deleted records', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/RecycleBinRecord')), new OA\Property(property: 'meta', type: 'object')])),
        new OA\Response(ref: '#/components/responses/PlatformUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlatformForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlatformUnprocessable', response: 422),
    ])]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', $this->registry->types())],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $type = $validated['type'];

        $page = $this->registry->trashed($type)->orderByDesc('deleted_at')->paginate((int) ($validated['per_page'] ?? 25));
        $records = $page->getCollection()->map(fn (Model $record): array => $this->present($type, $record))->values();

        return response()->json([
            'data' => $records,
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    #[OA\Post(path: '/api/v1/platform/recycle-bin/{type}/{id}/restore', operationId: 'restoreRecycleBinRecord', description: 'Brings a deleted record back. Recorded in the audit ledger.', summary: 'Restore a deleted record', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Platform'], parameters: [
        new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Restored', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RecycleBinRecord')])),
        new OA\Response(ref: '#/components/responses/PlatformUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlatformForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlatformNotFound', response: 404),
    ])]
    public function restore(string $type, string $id): JsonResponse
    {
        $record = $this->resolve($type, $id);

        if (! method_exists($record, 'restore')) {
            abort(404);
        }

        $record->restore();

        $this->audit->record('RECYCLE_BIN_RESTORE', $record, null, ['type' => $type, 'id' => $id], 'PLATFORM');

        return response()->json(['data' => $this->present($type, $record)]);
    }

    #[OA\Delete(path: '/api/v1/platform/recycle-bin/{type}/{id}', operationId: 'destroyRecycleBinRecord', description: 'Destroys a deleted record permanently. Types that carry legal retention (invoices) and records still referenced elsewhere are refused with 422. Recorded in the audit ledger.', summary: 'Destroy a deleted record for good', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Platform'], parameters: [
        new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 204, description: 'Destroyed'),
        new OA\Response(ref: '#/components/responses/PlatformUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlatformForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlatformNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlatformUnprocessable', response: 422),
    ])]
    public function forceDelete(string $type, string $id): JsonResponse
    {
        $record = $this->resolve($type, $id);

        if (! $this->registry->isPurgeable($type)) {
            return $this->unprocessable(__('platform.recycle_bin.not_purgeable'));
        }

        $this->audit->record('RECYCLE_BIN_PURGE', $record, ['type' => $type, 'label' => $this->registry->label($type, $record)], null, 'PLATFORM');

        try {
            $record->forceDelete();
        } catch (QueryException) {
            return $this->unprocessable(__('platform.recycle_bin.in_use'));
        }

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(string $type, Model $record): array
    {
        return [
            'id' => (string) $record->getKey(),
            'type' => $type,
            'label' => $this->registry->label($type, $record),
            'deleted_at' => $record->getAttribute('deleted_at')?->toIso8601String(),
            'purgeable' => $this->registry->isPurgeable($type),
        ];
    }

    private function resolve(string $type, string $id): Model
    {
        abort_unless($this->registry->has($type), 404);

        return $this->registry->find($type, $id) ?? abort(404);
    }

    private function unprocessable(string $detail): JsonResponse
    {
        return response()->json(['message' => $detail, 'errors' => ['record' => [$detail]]], 422);
    }
}
