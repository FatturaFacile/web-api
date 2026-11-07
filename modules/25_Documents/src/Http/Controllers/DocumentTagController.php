<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Documents\Http\Requests\StoreTagRequest;
use Modules\Documents\Http\Resources\TagResource;
use Modules\Documents\Models\DmsTag;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class DocumentTagController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/dms/tags',
        operationId: 'listDmsTags',
        description: 'Tags of the tenant.',
        summary: 'List tags',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Management'],
        responses: [
            new OA\Response(response: 200, description: 'Tags', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DmsTagResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse(TagResource::collection(DmsTag::query()->orderBy('name')->get()));
    }

    #[OA\Post(
        path: '/api/v1/dms/tags',
        operationId: 'createDmsTag',
        description: 'Create a tag.',
        summary: 'Create tag',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DmsStoreTagRequest')),
        tags: ['Document Management'],
        responses: [
            new OA\Response(response: 201, description: 'Tag created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsTagResource')])),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreTagRequest $request): JsonResponse
    {
        return $this->successResponse(new TagResource(DmsTag::create($request->validated())), 'Tag created', Response::HTTP_CREATED);
    }

    #[OA\Delete(
        path: '/api/v1/dms/tags/{id}',
        operationId: 'deleteDmsTag',
        description: 'Delete a tag and remove it from its documents.',
        summary: 'Delete tag',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Tag deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        DmsTag::query()->findOrFail($id)->delete();

        return $this->successResponse(null, 'Tag deleted');
    }
}
