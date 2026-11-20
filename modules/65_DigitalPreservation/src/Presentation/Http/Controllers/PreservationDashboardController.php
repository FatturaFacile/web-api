<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\DigitalPreservation\Application\Handlers\GetPreservationDashboardStatsHandler;
use Modules\DigitalPreservation\Application\Queries\GetPreservationDashboardStatsQuery;
use OpenApi\Attributes as OA;

final class PreservationDashboardController extends PreservationApiController
{
    #[OA\Get(path: '/api/v1/preservation/dashboard', operationId: 'getPreservationDashboard', description: 'KPIs of the digital preservation vault: preserved documents, WORM size, last sealed batch, chain status, pending documents and retention horizon.', summary: 'Preservation dashboard', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Digital Preservation'], responses: [
        new OA\Response(response: 200, description: 'The KPIs', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function __invoke(GetPreservationDashboardStatsHandler $handler): JsonResponse
    {
        return $this->successResponse($handler->handle(new GetPreservationDashboardStatsQuery));
    }
}
