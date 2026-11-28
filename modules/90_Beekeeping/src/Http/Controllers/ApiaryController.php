<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Beekeeping\Exceptions\BeekeepingException;
use Modules\Beekeeping\Http\Controllers\Concerns\ResolvesBeekeepingContext;
use Modules\Beekeeping\Http\Requests\SaveApiaryRequest;
use Modules\Beekeeping\Http\Resources\ApiaryResource;
use Modules\Beekeeping\Models\Apiary;
use Modules\Beekeeping\Models\Hive;
use OpenApi\Attributes as OA;

final class ApiaryController extends Controller
{
    use ApiResponse;
    use ResolvesBeekeepingContext;

    #[OA\Get(path: '/api/v1/beekeeping/apiaries', operationId: 'beekeepingApiariesList', summary: 'List the apiaries', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 200, description: 'Apiaries')])]
    public function index(): JsonResponse
    {
        $this->authorizeRead();

        return $this->successResponse(ApiaryResource::collection(Apiary::query()->withCount('hives')->orderBy('code')->get()));
    }

    #[OA\Post(path: '/api/v1/beekeeping/apiaries', operationId: 'beekeepingApiariesCreate', summary: 'Register an apiary', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 201, description: 'Apiary created')])]
    public function store(SaveApiaryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['foraging_radius_m'] ??= (int) config('beekeeping.foraging_radius_m');
        $apiary = new Apiary($data + ['created_by' => $this->userId($request)]);
        $apiary->save();

        return $this->successResponse(new ApiaryResource($apiary), $this->message('saved'), 201);
    }

    #[OA\Get(path: '/api/v1/beekeeping/apiaries/{id}', operationId: 'beekeepingApiariesShow', summary: 'Show an apiary', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Apiary')])]
    public function show(string $id): JsonResponse
    {
        $this->authorizeRead();

        return $this->successResponse(new ApiaryResource(Apiary::query()->withCount('hives')->findOrFail($id)));
    }

    #[OA\Put(path: '/api/v1/beekeeping/apiaries/{id}', operationId: 'beekeepingApiariesUpdate', summary: 'Update an apiary', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Apiary updated')])]
    public function update(SaveApiaryRequest $request, string $id): JsonResponse
    {
        $apiary = Apiary::query()->findOrFail($id);
        $apiary->fill($request->validated());
        $apiary->updated_by = $this->userId($request);
        $apiary->save();

        return $this->successResponse(new ApiaryResource($apiary->refresh()), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/beekeeping/apiaries/{id}', operationId: 'beekeepingApiariesDelete', summary: 'Delete an apiary without hives', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Apiary deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $this->authorizeWrite();
        $apiary = Apiary::query()->findOrFail($id);

        if (Hive::query()->where('apiary_id', $apiary->id)->exists()) {
            throw new BeekeepingException('An apiary with hives cannot be deleted.', 'apiary_has_hives');
        }

        $apiary->delete();

        return $this->successResponse(null, $this->message('deleted'));
    }
}
