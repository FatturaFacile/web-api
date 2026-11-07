<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Customs\Enums\MasterDataType;
use Modules\Customs\Http\Requests\SaveMasterDataRequest;
use Modules\Customs\Http\Resources\CustomsRecordResource;
use Modules\Customs\Models\CustomsMasterRecord;
use OpenApi\Attributes as OA;

/**
 * The customs code tables. Generic rows are read-only; a company adds its own codes or overrides a generic one.
 */
final class MasterDataController extends CustomsController
{
    /**
     * List a code table.
     */
    #[OA\Get(path: '/api/v1/customs/master-data/{type}', operationId: 'listCustomsMasterData', description: 'Return the rows of a code table: those of the company and the generic ones. A company row replaces the generic row with the same code.', summary: 'List a customs code table', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/CustomsMasterDataType'),
        new OA\Parameter(name: 'search', description: 'Part of the code or of a name', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'valid_on', description: 'Only the codes valid on this day', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
    ], responses: [
        new OA\Response(response: 200, description: 'Rows', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
    ])]
    public function index(Request $request, string $type): AnonymousResourceCollection
    {
        $model = $this->modelOf($type);
        $this->authorize('viewAny', $model);

        $search = trim((string) $request->query('search'));

        $query = $model::query()
            ->when($search !== '', static fn ($query) => $query->where(static fn ($inner) => $inner
                ->where('code', 'ilike', "{$search}%")
                ->orWhere('name_de', 'ilike', "%{$search}%")
                ->orWhere('name_fr', 'ilike', "%{$search}%")
                ->orWhere('name_it', 'ilike', "%{$search}%")
                ->orWhere('name_en', 'ilike', "%{$search}%")))
            ->when($request->filled('valid_on'), static fn ($query) => $query->validOn((string) $request->query('valid_on')))
            ->orderBy('code')
            ->orderByRaw('tenant_id is null');

        return CustomsRecordResource::collection($query->paginate($this->perPage($request, 50, 200)));
    }

    /**
     * Add a code.
     */
    #[OA\Post(path: '/api/v1/customs/master-data/{type}', operationId: 'storeCustomsMasterData', description: 'Add a code of the company, or override a generic code with the same code.', summary: 'Add a customs code', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CustomsMasterDataRequest')), tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsMasterDataType')], responses: [
        new OA\Response(response: 201, description: 'Code added', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function store(SaveMasterDataRequest $request, string $type): JsonResponse
    {
        $model = $this->modelOf($type);
        $this->authorize('create', $model);

        return (new CustomsRecordResource($model::query()->create([...$request->validated(), 'source' => 'tenant'])))->response()->setStatusCode(201);
    }

    /**
     * Change a code.
     */
    #[OA\Put(path: '/api/v1/customs/master-data/{type}/{id}', operationId: 'updateCustomsMasterData', description: 'Change a code of the company. Generic rows are read-only.', summary: 'Change a customs code', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CustomsMasterDataRequest')), tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsMasterDataType'), new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Code changed', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function update(SaveMasterDataRequest $request, string $type, string $id): CustomsRecordResource
    {
        $record = $this->modelOf($type)::query()->findOrFail($id);
        $this->authorize('update', $record);

        $record->update($request->validated());

        return new CustomsRecordResource($record);
    }

    /**
     * Delete a code.
     */
    #[OA\Delete(path: '/api/v1/customs/master-data/{type}/{id}', operationId: 'destroyCustomsMasterData', description: 'Delete a code of the company. Generic rows are read-only.', summary: 'Delete a customs code', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsMasterDataType'), new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 204, description: 'Code deleted'),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
    ])]
    public function destroy(string $type, string $id): Response
    {
        $record = $this->modelOf($type)::query()->findOrFail($id);
        $this->authorize('delete', $record);

        $record->delete();

        return response()->noContent();
    }

    /**
     * @return class-string<CustomsMasterRecord>
     */
    private function modelOf(string $type): string
    {
        $table = MasterDataType::tryFrom($type);

        abort_if($table === null, 404);

        return $table->modelClass();
    }
}
