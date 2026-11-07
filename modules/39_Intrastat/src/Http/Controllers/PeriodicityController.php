<?php

declare(strict_types=1);

namespace Modules\Intrastat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Intrastat\Http\Requests\PeriodicityRequest;
use Modules\Intrastat\Models\IntrastatDeclaration;
use Modules\Intrastat\Services\PeriodicityAdvisor;
use OpenApi\Attributes as OA;

final class PeriodicityController extends Controller
{
    use ApiResponse;

    #[OA\Get(path: '/api/v1/intrastat/periodicity', operationId: 'showIntrastatPeriodicity', description: 'Measure the intra-community operations of the four quarters before the given quarter, category by category, and apply the current thresholds: sales of goods and services rendered quarterly while no quarter exceeds 50,000 euro; statistical data of the monthly INTRA-1 bis from 100,000 euro; monthly INTRA-2 bis (statistical only) from 2,000,000 euro for periods from 2026 (350,000 euro before); monthly INTRA-2 quater (statistical only) from 100,000 euro. Also reports whether the goods flows of the previous year exceeded 20 million euro. Only classified invoice lines are counted; unclassified amounts are returned apart.', summary: 'Advise INTRA periodicity', security: [['bearerAuth' => []]], tags: ['Intrastat'], parameters: [
        new OA\Parameter(name: 'year', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 2027)),
        new OA\Parameter(name: 'quarter', in: 'query', required: true, schema: new OA\Schema(type: 'integer', maximum: 4, minimum: 1, example: 2)),
    ], responses: [
        new OA\Response(response: 200, description: 'Periodicity advice', content: new OA\JsonContent(ref: '#/components/schemas/IntrastatPeriodicityResponse')),
        new OA\Response(ref: '#/components/responses/IntrastatUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/IntrastatForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/IntrastatUnprocessable', response: 422),
    ])]
    public function show(PeriodicityRequest $request, PeriodicityAdvisor $advisor): JsonResponse
    {
        $this->authorize('viewAny', IntrastatDeclaration::class);

        return $this->successResponse($advisor->advise((int) $request->validated('year'), (int) $request->validated('quarter')), 'Periodicity advice');
    }
}
