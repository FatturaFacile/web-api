<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\TalentLearning\Contracts\HrDirectory;
use Modules\TalentLearning\Http\Controllers\Concerns\InteractsWithTalent;
use Modules\TalentLearning\Models\LmsCourse;
use OpenApi\Attributes as OA;

/**
 * Pickers of employees and positions of human resources, without any pay or personal identifier.
 */
final class LookupController extends Controller
{
    use InteractsWithTalent;

    public function __construct(private readonly HrDirectory $directory) {}

    #[OA\Get(path: '/api/v1/talent-learning/lookups/employees', operationId: 'lookupTalentEmployees', description: 'Active employees by name, for pickers: id, name, number, position and department. Filters: search (name or employee number), limit (default 25, max 100). For people managers.', summary: 'Look up employees', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], responses: [
        new OA\Response(response: 200, description: 'Employees', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TalentEmployeeLookup'))])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function employees(Request $request): JsonResponse
    {
        $this->authorize('lookup', LmsCourse::class);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json(['data' => array_map(
            static fn ($employee): array => $employee->toArray(),
            $this->directory->activeEmployees($this->tenantId(), $filters['search'] ?? null, (int) ($filters['limit'] ?? 25)),
        )]);
    }

    #[OA\Get(path: '/api/v1/talent-learning/lookups/positions', operationId: 'lookupTalentPositions', description: 'Active job positions by title, for pickers. Filters: search (code or title), limit (default 25, max 100). For people managers.', summary: 'Look up positions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], responses: [
        new OA\Response(response: 200, description: 'Positions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TalentPositionLookup'))])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function positions(Request $request): JsonResponse
    {
        $this->authorize('lookup', LmsCourse::class);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json(['data' => array_map(
            static fn ($position): array => $position->toArray(),
            $this->directory->positions($this->tenantId(), $filters['search'] ?? null, (int) ($filters['limit'] ?? 25)),
        )]);
    }
}
