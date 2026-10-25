<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StoreAlertRequest;
use App\Http\Resources\CatalogResource;
use App\Models\Alert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;

class AlertController extends Controller
{
    use HandlesLookupCrud {
        index as traitIndex;
        show as traitShow;
        destroy as traitDestroy;
    }

    protected function modelClass(): string
    {
        return Alert::class;
    }

    protected function label(): string
    {
        return __('api.labels.alert');
    }

    #[OA\Get(
        path: '/api/v1/alerts',
        operationId: 'getAlerts',
        description: 'List paginated operation and fiscal alerts.',
        summary: 'List alerts',
        security: [['bearerAuth' => []]],
        tags: ['Alerts', 'Operations'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Alerts retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        return $this->traitIndex($request);
    }

    #[OA\Post(
        path: '/api/v1/alerts',
        operationId: 'createAlert',
        description: 'Create a new operational alert or notification.',
        summary: 'Create alert',
        security: [['bearerAuth' => []]],
        tags: ['Alerts', 'Operations'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title'],
                properties: [
                    new OA\Property(property: 'title', type: 'string', maxLength: 255, example: 'Quarterly VAT due soon'),
                    new OA\Property(property: 'message', type: 'string', maxLength: 2000, nullable: true),
                    new OA\Property(property: 'severity', type: 'string', enum: ['info', 'warning', 'critical'], default: 'info'),
                    new OA\Property(property: 'alertable_type', type: 'string', nullable: true),
                    new OA\Property(property: 'alertable_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'is_active', type: 'boolean', default: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Alert created successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreAlertRequest $request): JsonResponse
    {
        return $this->createRecord(array_merge($this->payload($request->validated()), ['created_by' => Auth::id()]));
    }

    #[OA\Get(
        path: '/api/v1/alerts/{id}',
        operationId: 'getAlertById',
        description: 'Get details of an alert by ID.',
        summary: 'Get alert details',
        security: [['bearerAuth' => []]],
        tags: ['Alerts', 'Operations'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Alert retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Alert not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->traitShow($id);
    }

    #[OA\Put(
        path: '/api/v1/alerts/{id}',
        operationId: 'updateAlert',
        description: 'Update an existing alert.',
        summary: 'Update alert',
        security: [['bearerAuth' => []]],
        tags: ['Alerts', 'Operations'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent
        ),
        responses: [
            new OA\Response(response: 200, description: 'Alert updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Alert not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(StoreAlertRequest $request, string $id): JsonResponse
    {
        return $this->updateRecord($id, $this->payload($request->validated()));
    }

    #[OA\Delete(
        path: '/api/v1/alerts/{id}',
        operationId: 'deleteAlert',
        description: 'Delete an alert.',
        summary: 'Delete alert',
        security: [['bearerAuth' => []]],
        tags: ['Alerts', 'Operations'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Alert deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Alert not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        return $this->traitDestroy($id);
    }

    /**
     * Alerts currently in force: global ones plus those attached to the given contact/document.
     */
    #[OA\Get(
        path: '/api/v1/alerts/active',
        operationId: 'getActiveAlerts',
        description: 'Alerts currently in force: global ones plus those attached to the given contact/document.',
        summary: 'Get active alerts',
        security: [['bearerAuth' => []]],
        tags: ['Alerts', 'Operations'],
        parameters: [
            new OA\Parameter(name: 'alertable_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'alertable_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Active alerts retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function active(Request $request): JsonResponse
    {
        $type = $request->query('alertable_type');
        $targetClass = is_string($type) ? (Alert::TARGET_TYPES[$type] ?? null) : null;

        $alerts = Alert::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->where(function ($q) use ($targetClass, $request): void {
                $q->whereNull('alertable_id');

                if ($targetClass !== null && $request->filled('alertable_id')) {
                    $q->orWhere(fn ($t) => $t->where('alertable_type', $targetClass)->where('alertable_id', $request->query('alertable_id')));
                }
            })
            ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
            ->get();

        return $this->successResponse(CatalogResource::collection($alerts), __('alerts.messages.active'));
    }

    /**
     * Translate the API alias into the model class and verify the target belongs to the tenant.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        if (isset($data['alertable_type'], $data['alertable_id'])) {
            $class = Alert::TARGET_TYPES[$data['alertable_type']];
            $class::findOrFail($data['alertable_id']);
            $data['alertable_type'] = $class;
        }

        return $data;
    }
}
