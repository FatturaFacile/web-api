<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\GreenCare\Application\Services\CrewWriter;
use Modules\GreenCare\Http\Controllers\Concerns\InteractsWithGreenCare;
use Modules\GreenCare\Http\Requests\SaveCrewRequest;
use Modules\GreenCare\Http\Resources\CrewResource;
use Modules\GreenCare\Models\CrewTeam;
use OpenApi\Attributes as OA;

/**
 * Crews with their members, vehicle, trailer and equipment.
 */
final class CrewController extends Controller
{
    use ApiResponse;
    use InteractsWithGreenCare;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/green-care/crews', operationId: 'greenCareCrewsIndex', summary: 'Crews with members and licenses', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Crews')])]
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', CrewTeam::class);
        $page = CrewTeam::query()->with('members.licenses')->orderBy('code')->paginate($this->perPage(100));

        return $this->paged($page, CrewResource::class);
    }

    #[OA\Post(path: '/api/v1/green-care/crews', operationId: 'greenCareCrewsStore', summary: 'Create a crew', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 201, description: 'Crew')])]
    public function store(SaveCrewRequest $request, CrewWriter $writer): JsonResponse
    {
        Gate::authorize('create', CrewTeam::class);

        return $this->createdResponse(new CrewResource($writer->save(null, $request->validated(), $this->tenantId())), $this->message('crew_saved'));
    }

    #[OA\Get(path: '/api/v1/green-care/crews/{id}', operationId: 'greenCareCrewsShow', summary: 'One crew', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Crew')])]
    public function show(string $id): JsonResponse
    {
        $crew = CrewTeam::query()->with('members.licenses')->findOrFail($id);
        Gate::authorize('view', $crew);

        return $this->successResponse(new CrewResource($crew), $this->message('loaded'));
    }

    #[OA\Put(path: '/api/v1/green-care/crews/{id}', operationId: 'greenCareCrewsUpdate', summary: 'Change a crew and its members', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Crew')])]
    public function update(SaveCrewRequest $request, string $id, CrewWriter $writer): JsonResponse
    {
        $crew = CrewTeam::query()->findOrFail($id);
        Gate::authorize('update', $crew);

        return $this->successResponse(new CrewResource($writer->save($crew, $request->validated(), $this->tenantId())), $this->message('crew_saved'));
    }

    #[OA\Delete(path: '/api/v1/green-care/crews/{id}', operationId: 'greenCareCrewsDestroy', summary: 'Delete a crew', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $crew = CrewTeam::query()->findOrFail($id);
        Gate::authorize('delete', $crew);
        $crew->delete();

        return $this->successResponse(null, $this->message('crew_deleted'));
    }
}
