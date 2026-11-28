<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\WithholdingRecipients\Application\Queries\GetRecipientDashboardHandler;
use Modules\WithholdingRecipients\Application\Queries\GetRecipientDashboardQuery;
use Modules\WithholdingRecipients\Application\Queries\GetThresholdProgressHandler;
use Modules\WithholdingRecipients\Application\Queries\GetThresholdProgressQuery;
use Modules\WithholdingRecipients\Http\Requests\DashboardRequest;
use Modules\WithholdingRecipients\Models\RecipientProfile;
use OpenApi\Attributes as OA;

/**
 * Recipients dashboard (filters, year-to-date earnings, compliance alerts) and the threshold progress of one recipient.
 */
final class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly GetRecipientDashboardHandler $dashboard,
        private readonly GetThresholdProgressHandler $thresholds,
    ) {}

    #[OA\Get(path: '/api/v1/withholding-recipients/dashboard', operationId: 'withholdingRecipientsDashboard', summary: 'Recipients dashboard with year-to-date earnings and compliance alerts', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], responses: [new OA\Response(response: 200, description: 'Dashboard rows and summary')])]
    public function index(DashboardRequest $request): JsonResponse
    {
        $result = $this->dashboard->handle(new GetRecipientDashboardQuery($request->validated()));

        return $this->successResponse($result['rows'], 'OK', 200, $result['summary']);
    }

    #[OA\Get(path: '/api/v1/withholding-recipients/recipients/{id}/thresholds', operationId: 'withholdingRecipientsThresholds', summary: 'Progress of a recipient against the statutory thresholds', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Thresholds')])]
    public function thresholds(DashboardRequest $request, string $id): JsonResponse
    {
        Gate::authorize('viewAny', RecipientProfile::class);
        $year = (int) ($request->validated('year') ?? CarbonImmutable::today()->year);

        return $this->successResponse($this->thresholds->handle(new GetThresholdProgressQuery($id, $year)), 'OK', 200, ['year' => $year]);
    }
}
