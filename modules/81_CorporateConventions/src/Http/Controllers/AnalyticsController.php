<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\CorporateConventions\Application\Queries\GetCorporateConventionsRoiAnalyticsHandler;
use Modules\CorporateConventions\Application\Queries\GetCorporateConventionsRoiAnalyticsQuery;
use Modules\CorporateConventions\Http\Controllers\Concerns\InteractsWithConventions;
use Modules\CorporateConventions\Http\Requests\AnalyticsFiltersRequest;
use Modules\CorporateConventions\Models\CorporatePartnerConvention;
use Modules\CorporateConventions\Services\ConventionRoiService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ROI dashboard of the commercial director.
 */
final class AnalyticsController extends Controller
{
    use ApiResponse;
    use InteractsWithConventions;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/sales/corporate-conventions/analytics/roi', operationId: 'corporateConventionsRoi', summary: 'ROI and KPIs of the conventions: enrolment, revenue, discount, employer quota and net margin', security: self::SECURITY, tags: ['Corporate Conventions'], responses: [new OA\Response(response: 200, description: 'KPIs and one row per convention')])]
    public function roi(AnalyticsFiltersRequest $request, GetCorporateConventionsRoiAnalyticsHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', CorporatePartnerConvention::class);

        return $this->successResponse($handler->handle(new GetCorporateConventionsRoiAnalyticsQuery($request->validated())), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/sales/corporate-conventions/analytics/roi/export', operationId: 'corporateConventionsRoiExport', summary: 'ROI of the conventions as CSV', security: self::SECURITY, tags: ['Corporate Conventions'], responses: [new OA\Response(response: 200, description: 'CSV file')])]
    public function export(AnalyticsFiltersRequest $request, ConventionRoiService $roi): StreamedResponse
    {
        Gate::authorize('viewAny', CorporatePartnerConvention::class);
        $rows = $roi->exportRows($request->validated());

        return response()->streamDownload(static function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                fputcsv($out, $row, ';', '"', '');
            }

            fclose($out);
        }, 'corporate-conventions-roi.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
