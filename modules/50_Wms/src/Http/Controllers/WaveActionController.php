<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Wms\Http\Controllers\Concerns\InteractsWithWms;
use Modules\Wms\Http\Resources\WaveResource;
use Modules\Wms\Models\WmsWave;
use Modules\Wms\Services\WaveService;
use OpenApi\Attributes as OA;

/**
 * Wave planning: group the open pick tasks with a wave rule, then release, complete or cancel the wave.
 */
final class WaveActionController extends Controller
{
    use InteractsWithWms;

    public function __construct(private readonly WaveService $waves) {}

    #[OA\Post(path: '/api/v1/wms/waves/plan', operationId: 'planWmsWave', description: 'Collects the open, unassigned pick tasks of the warehouse of the rule that match it (minimum priority, due window, reference prefix), most urgent first up to the maximum size, and groups them in a planned wave. Refused when fewer tasks than the minimum match.', summary: 'Plan a wave', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['wave_rule_id'], properties: [new OA\Property(property: 'wave_rule_id', type: 'string', format: 'uuid')])), responses: [
        new OA\Response(response: 201, description: 'The planned wave', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsWave')])),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsHubUnprocessable', response: 422),
    ])]
    public function plan(Request $request): JsonResponse
    {
        $this->authorize('create', WmsWave::class);
        $data = $request->validate([
            'wave_rule_id' => ['required', 'uuid', Rule::exists('wms_wave_rules', 'id')->where('tenant_id', $this->tenantId())->whereNull('deleted_at')],
        ]);

        return (new WaveResource($this->waves->plan($this->tenantId(), (string) $data['wave_rule_id'])))->response()->setStatusCode(201);
    }

    #[OA\Post(path: '/api/v1/wms/waves/{id}/release', operationId: 'releaseWmsWave', description: 'Releases a planned wave to the floor: its tasks can now be claimed by the operators.', summary: 'Release a wave', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsWave')])),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsHubUnprocessable', response: 422),
    ])]
    public function release(string $id): WaveResource
    {
        $record = WmsWave::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new WaveResource($this->waves->release($record));
    }

    #[OA\Post(path: '/api/v1/wms/waves/{id}/complete', operationId: 'completeWmsWave', description: 'Closes a released wave once every one of its tasks is closed (completed, short, skipped or cancelled).', summary: 'Complete a wave', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsWave')])),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsHubUnprocessable', response: 422),
    ])]
    public function complete(string $id): WaveResource
    {
        $record = WmsWave::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new WaveResource($this->waves->complete($record));
    }

    #[OA\Post(path: '/api/v1/wms/waves/{id}/cancel', operationId: 'cancelWmsWave', description: 'Cancels a wave in which no task has started and gives its tasks back to the open queue.', summary: 'Cancel a wave', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsWave')])),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsHubUnprocessable', response: 422),
    ])]
    public function cancel(string $id): WaveResource
    {
        $record = WmsWave::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new WaveResource($this->waves->cancel($record));
    }
}
