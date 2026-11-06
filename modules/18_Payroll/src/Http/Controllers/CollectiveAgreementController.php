<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Modules\Payroll\Http\Requests\SaveAgreementLevelRequest;
use Modules\Payroll\Http\Requests\SaveCollectiveAgreementRequest;
use Modules\Payroll\Http\Resources\PayrollRecordResource;
use Modules\Payroll\Models\CollectiveAgreement;
use Modules\Payroll\Models\CollectiveAgreementLevel;
use OpenApi\Attributes as OA;

/**
 * Collective agreements (CCNL) and their levels: the tables are loaded by the user.
 */
final class CollectiveAgreementController extends PayrollController
{
    /**
     * List collective agreements.
     */
    #[OA\Get(
        path: '/api/v1/payroll/collective-agreements',
        operationId: 'listCollectiveAgreements',
        description: 'Return the collective agreements of the company and the generic ones, with their levels.',
        summary: 'List collective agreements',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        responses: [
            new OA\Response(response: 200, description: 'Collective agreements', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CollectiveAgreement::class);

        return PayrollRecordResource::collection(CollectiveAgreement::query()->with('levels')->orderBy('code')->paginate($this->perPage($request, 50)));
    }

    /**
     * Create a collective agreement.
     */
    #[OA\Post(
        path: '/api/v1/payroll/collective-agreements',
        operationId: 'storeCollectiveAgreement',
        description: 'Create a collective agreement of the company.',
        summary: 'Create collective agreement',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollCollectiveAgreementRequest')),
        tags: ['Payroll'],
        responses: [
            new OA\Response(response: 201, description: 'Collective agreement created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function store(SaveCollectiveAgreementRequest $request): JsonResponse
    {
        $this->authorize('create', CollectiveAgreement::class);

        return (new PayrollRecordResource(CollectiveAgreement::query()->create($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Show a collective agreement.
     */
    #[OA\Get(
        path: '/api/v1/payroll/collective-agreements/{id}',
        operationId: 'showCollectiveAgreement',
        description: 'Return a collective agreement with its levels.',
        summary: 'Show collective agreement',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Collective agreement', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        ]
    )]
    public function show(string $id): PayrollRecordResource
    {
        $agreement = CollectiveAgreement::query()->findOrFail($id);
        $this->authorize('view', $agreement);

        return new PayrollRecordResource($agreement->load('levels'));
    }

    /**
     * Change a collective agreement.
     */
    #[OA\Put(
        path: '/api/v1/payroll/collective-agreements/{id}',
        operationId: 'updateCollectiveAgreement',
        description: 'Change a collective agreement of the company. Generic agreements are read-only.',
        summary: 'Update collective agreement',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollCollectiveAgreementRequest')),
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Collective agreement saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function update(SaveCollectiveAgreementRequest $request, string $id): PayrollRecordResource
    {
        $agreement = CollectiveAgreement::query()->findOrFail($id);
        $this->authorize('update', $agreement);
        $agreement->update($request->validated());

        return new PayrollRecordResource($agreement->refresh());
    }

    /**
     * Delete a collective agreement.
     */
    #[OA\Delete(
        path: '/api/v1/payroll/collective-agreements/{id}',
        operationId: 'deleteCollectiveAgreement',
        description: 'Delete a collective agreement of the company with its levels. Generic agreements are read-only.',
        summary: 'Delete collective agreement',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 204, description: 'Collective agreement deleted'),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        ]
    )]
    public function destroy(string $id): HttpResponse
    {
        $agreement = CollectiveAgreement::query()->findOrFail($id);
        $this->authorize('delete', $agreement);
        $agreement->delete();

        return response()->noContent();
    }

    /**
     * Add a level.
     */
    #[OA\Post(
        path: '/api/v1/payroll/collective-agreements/{id}/levels',
        operationId: 'storeCollectiveAgreementLevel',
        description: 'Add a level with its monthly minimum to a collective agreement of the company.',
        summary: 'Create agreement level',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollAgreementLevelRequest')),
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 201, description: 'Level created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function storeLevel(SaveAgreementLevelRequest $request, string $id): JsonResponse
    {
        $agreement = CollectiveAgreement::query()->findOrFail($id);
        $this->authorize('update', $agreement);
        $level = CollectiveAgreementLevel::query()->create([...$request->validated(), 'agreement_id' => $agreement->id]);

        return (new PayrollRecordResource($level))->response()->setStatusCode(201);
    }

    /**
     * Change a level.
     */
    #[OA\Put(
        path: '/api/v1/payroll/collective-agreements/{id}/levels/{levelId}',
        operationId: 'updateCollectiveAgreementLevel',
        description: 'Change a level of a collective agreement of the company.',
        summary: 'Update agreement level',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollAgreementLevelRequest')),
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId'), new OA\Parameter(ref: '#/components/parameters/PayrollLevelId')],
        responses: [
            new OA\Response(response: 200, description: 'Level saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function updateLevel(SaveAgreementLevelRequest $request, string $id, string $levelId): PayrollRecordResource
    {
        $level = CollectiveAgreementLevel::query()->where('agreement_id', $id)->findOrFail($levelId);
        $this->authorize('update', $level);
        $level->update($request->validated());

        return new PayrollRecordResource($level->refresh());
    }

    /**
     * Delete a level.
     */
    #[OA\Delete(
        path: '/api/v1/payroll/collective-agreements/{id}/levels/{levelId}',
        operationId: 'deleteCollectiveAgreementLevel',
        description: 'Delete a level of a collective agreement of the company.',
        summary: 'Delete agreement level',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId'), new OA\Parameter(ref: '#/components/parameters/PayrollLevelId')],
        responses: [
            new OA\Response(response: 204, description: 'Level deleted'),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        ]
    )]
    public function destroyLevel(string $id, string $levelId): HttpResponse
    {
        $level = CollectiveAgreementLevel::query()->where('agreement_id', $id)->findOrFail($levelId);
        $this->authorize('delete', $level);
        $level->delete();

        return response()->noContent();
    }
}
