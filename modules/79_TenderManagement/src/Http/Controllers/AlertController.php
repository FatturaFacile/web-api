<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\TenderManagement\Http\Controllers\Concerns\InteractsWithTenders;
use Modules\TenderManagement\Http\Requests\ListFiltersRequest;
use Modules\TenderManagement\Http\Resources\AlertResource;
use Modules\TenderManagement\Models\TenderAlert;
use OpenApi\Attributes as OA;

/**
 * The alerts raised by the deadline and vault expiry scan.
 */
final class AlertController extends Controller
{
    use ApiResponse;
    use InteractsWithTenders;

    #[OA\Get(path: '/api/v1/tender-management/alerts', operationId: 'tenderManagementListAlerts', summary: 'Deadline and expiry alerts', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], responses: [new OA\Response(response: 200, description: 'Alerts, paginated')])]
    public function index(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', TenderAlert::class);

        $page = TenderAlert::query()
            ->when($request->has('acknowledged'), fn ($query) => $request->boolean('acknowledged') ? $query->whereNotNull('acknowledged_at') : $query->whereNull('acknowledged_at'))
            ->orderByDesc('raised_at')
            ->paginate($this->perPage());

        return $this->paged($page, AlertResource::class, $this->message('listed'));
    }

    #[OA\Post(path: '/api/v1/tender-management/alerts/{id}/acknowledge', operationId: 'tenderManagementAcknowledgeAlert', summary: 'Acknowledge an alert', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Alert acknowledged')])]
    public function acknowledge(string $id): JsonResponse
    {
        $alert = TenderAlert::query()->findOrFail($id);
        Gate::authorize('view', $alert);
        $alert->forceFill(['acknowledged_at' => now(), 'acknowledged_by' => $this->userId()])->save();

        return $this->successResponse(new AlertResource($alert), $this->message('acknowledged'));
    }
}
