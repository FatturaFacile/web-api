<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\TalentLearning\Http\Controllers\Concerns\InteractsWithTalent;
use Modules\TalentLearning\Models\LmsCourse;
use Modules\TalentLearning\Services\LearningService;
use OpenApi\Attributes as OA;

/**
 * Compliance with mandatory training.
 */
final class ComplianceController extends Controller
{
    use InteractsWithTalent;

    public function __construct(private readonly LearningService $learning) {}

    #[OA\Get(path: '/api/v1/talent-learning/compliance', operationId: 'showTalentCompliance', description: 'For each published mandatory course: active employees, how many are compliant (valid certificate), in progress, overdue, expired or missing, and the compliance percent. With course_id the employees who are not compliant are listed. For learning managers.', summary: 'Mandatory training compliance', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(name: 'course_id', description: 'Narrow to one course and list its non-compliant employees', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Compliance', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentCompliance')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function show(Request $request): JsonResponse
    {
        $this->authorize('manage', LmsCourse::class);
        $filters = $request->validate(['course_id' => ['nullable', 'uuid']]);

        return response()->json(['data' => $this->learning->compliance($this->tenantId(), $filters['course_id'] ?? null)]);
    }
}
