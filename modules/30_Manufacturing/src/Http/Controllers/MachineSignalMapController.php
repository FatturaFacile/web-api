<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Manufacturing\Http\Controllers\Concerns\InteractsWithManufacturing;
use Modules\Manufacturing\Http\Requests\SaveMachineSignalMapRequest;
use Modules\Manufacturing\Http\Resources\MfgMachineSignalMapResource;
use Modules\Manufacturing\Models\MfgMachineSignalMap;
use OpenApi\Attributes as OA;

/**
 * The per-machine mapping of OPC UA nodes, MQTT topics and Modbus registers to machine signals.
 */
final class MachineSignalMapController extends Controller
{
    use InteractsWithManufacturing;

    /**
     * Signal mappings of the company.
     */
    #[OA\Get(path: '/api/v1/manufacturing/machine-signal-maps', operationId: 'listMfgMachineSignalMaps', description: 'Mappings of protocol signals (OPC UA node, MQTT topic, Modbus register) to machine signals, optionally of one machine or protocol.', summary: 'List machine signal mappings', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Edge'],
        parameters: [new OA\Parameter(name: 'machine_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'protocol', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['opcua', 'mqtt', 'modbus']))],
        responses: [new OA\Response(response: 200, description: 'Mappings'), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401)])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = MfgMachineSignalMap::query()->where('tenant_id', $this->tenantId());

        if ($request->filled('machine_id')) {
            $query->where('machine_id', (string) $request->query('machine_id'));
        }

        if ($request->filled('protocol')) {
            $query->where('protocol', (string) $request->query('protocol'));
        }

        return MfgMachineSignalMapResource::collection($query->orderBy('machine_id')->orderBy('protocol')->orderBy('source_key')->get());
    }

    /**
     * Creates a mapping.
     */
    #[OA\Post(path: '/api/v1/manufacturing/machine-signal-maps', operationId: 'createMfgMachineSignalMap', description: 'Maps a protocol signal to a signal of a machine: state (with a raw value to state map), piece counter, spindle speed, energy or power (with data type, scale and offset).', summary: 'Create machine signal mapping', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Edge'],
        responses: [new OA\Response(response: 201, description: 'Mapping created'), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function store(SaveMachineSignalMapRequest $request): JsonResponse
    {
        $map = MfgMachineSignalMap::create(['tenant_id' => $this->tenantId(), ...$request->validated()]);

        return (new MfgMachineSignalMapResource($map->refresh()))->response()->setStatusCode(201);
    }

    /**
     * Updates a mapping.
     */
    #[OA\Put(path: '/api/v1/manufacturing/machine-signal-maps/{id}', operationId: 'updateMfgMachineSignalMap', description: 'Replaces the definition of a machine signal mapping.', summary: 'Update machine signal mapping', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Edge'], parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Mapping updated'), new OA\Response(response: 404, description: 'Not found'), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function update(SaveMachineSignalMapRequest $request, string $id): MfgMachineSignalMapResource
    {
        $map = $this->owned(MfgMachineSignalMap::class, $id);
        $map->update($request->validated());

        return new MfgMachineSignalMapResource($map->refresh());
    }

    /**
     * Deletes a mapping.
     */
    #[OA\Delete(path: '/api/v1/manufacturing/machine-signal-maps/{id}', operationId: 'deleteMfgMachineSignalMap', summary: 'Delete machine signal mapping', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Edge'], parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 204, description: 'Deleted'), new OA\Response(response: 404, description: 'Not found')])]
    public function destroy(string $id): JsonResponse
    {
        $this->owned(MfgMachineSignalMap::class, $id)->delete();

        return response()->json(null, 204);
    }
}
