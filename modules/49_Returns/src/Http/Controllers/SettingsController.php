<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Returns\Http\Controllers\Concerns\InteractsWithReturns;
use Modules\Returns\Http\Requests\ReasonCodeRequest;
use Modules\Returns\Http\Requests\ReturnsSettingsRequest;
use Modules\Returns\Http\Resources\ReasonCodeResource;
use Modules\Returns\Models\ReturnReasonCode;
use Modules\Returns\Models\ReturnsSetting;
use Modules\Returns\Services\ReturnsSettingsService;
use OpenApi\Attributes as OA;

/**
 * The return window and the reason codes of the company.
 */
final class SettingsController extends Controller
{
    use InteractsWithReturns;

    public function __construct(private readonly ReturnsSettingsService $settings) {}

    #[OA\Get(path: '/api/v1/returns/settings', operationId: 'getReturnsSettings', description: 'The return window in days and the reason codes of the company. A company that never opened the settings starts with 30 days and the default reason codes.', summary: 'Returns settings', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 200, description: 'Settings', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsSettings')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
    ])]
    public function show(): JsonResponse
    {
        $this->authorize('viewAny', ReturnsSetting::class);

        return response()->json(['data' => $this->payload()]);
    }

    #[OA\Put(path: '/api/v1/returns/settings', operationId: 'updateReturnsSettings', description: 'Change the return window: the days after the date of the invoice (or order) a customer can still return goods.', summary: 'Update the returns settings', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReturnsSettingsRequest')), tags: ['Returns'], responses: [
        new OA\Response(response: 200, description: 'Settings updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsSettings')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function update(ReturnsSettingsRequest $request): JsonResponse
    {
        $this->authorize('create', ReturnsSetting::class);
        $this->settings->updateWindow($this->tenantId(), (int) $request->validated()['return_window_days']);

        return response()->json(['data' => $this->payload()]);
    }

    #[OA\Get(path: '/api/v1/returns/reason-codes', operationId: 'listReturnsReasonCodes', description: 'The reason codes of the company. Filter: active (true: only the codes a new return can use).', summary: 'List reason codes', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], parameters: [
        new OA\Parameter(name: 'active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
    ], responses: [
        new OA\Response(response: 200, description: 'Reason codes', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ReturnsReasonCode'))])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
    ])]
    public function reasons(): JsonResponse
    {
        $this->authorize('viewAny', ReturnReasonCode::class);

        return response()->json(['data' => ReasonCodeResource::collection($this->settings->reasonCodes($this->tenantId(), request()->boolean('active')))->resolve()]);
    }

    #[OA\Post(path: '/api/v1/returns/reason-codes', operationId: 'storeReturnsReasonCode', description: 'Add a reason code (lowercase letters, digits and underscores, unique in the company).', summary: 'Create a reason code', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReturnsReasonCodeRequest')), tags: ['Returns'], responses: [
        new OA\Response(response: 201, description: 'Reason code created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsReasonCode')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function storeReason(ReasonCodeRequest $request): JsonResponse
    {
        $this->authorize('create', ReturnReasonCode::class);

        return (new ReasonCodeResource($this->settings->createReason($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/returns/reason-codes/{id}', operationId: 'updateReturnsReasonCode', description: 'Change the label and the flags of a reason code. The code is fixed once created.', summary: 'Update a reason code', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReturnsReasonCodeRequest')), tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Reason code updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsReasonCode')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function updateReason(ReasonCodeRequest $request, string $id): ReasonCodeResource
    {
        $reason = ReturnReasonCode::query()->findOrFail($id);
        $this->authorize('update', $reason);

        return new ReasonCodeResource($this->settings->updateReason($reason, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/returns/reason-codes/{id}', operationId: 'deleteReturnsReasonCode', description: 'Remove a reason code. A code used by a return is deactivated instead and the response says so (deleted: false).', summary: 'Delete a reason code', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Reason code removed or deactivated', content: new OA\JsonContent(properties: [new OA\Property(property: 'deleted', type: 'boolean')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
    ])]
    public function destroyReason(string $id): JsonResponse
    {
        $reason = ReturnReasonCode::query()->findOrFail($id);
        $this->authorize('delete', $reason);

        return response()->json(['deleted' => $this->settings->deleteReason($reason)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $tenantId = $this->tenantId();

        return [
            'return_window_days' => $this->settings->windowDays($tenantId),
            'reason_codes' => ReasonCodeResource::collection($this->settings->reasonCodes($tenantId))->resolve(),
        ];
    }
}
