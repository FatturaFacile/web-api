<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tax;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tax\WithholdingReportRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Services\Invoicing\Tax\WithholdingReportService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * CU withholding report: withholdings of received supplier invoices paid in a year, per supplier and causale.
 */
class WithholdingReportController extends Controller
{
    use ApiResponse, AuthorizesRequests;

    public function __construct(private readonly WithholdingReportService $reports) {}

    #[OA\Get(
        path: '/api/v1/tax/withholdings/report',
        operationId: 'getWithholdingReport',
        description: 'Withholdings (RT01/RT02) of received supplier invoices grouped by supplier and causale, by payment year. Invoices not paid yet are listed in `unpaid_withholdings`. `format=csv` downloads a CSV.',
        summary: 'CU withholding report',
        security: [['bearerAuth' => []]],
        tags: ['Tax', 'Purchases'],
        parameters: [
            new OA\Parameter(name: 'year', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 2026)),
            new OA\Parameter(name: 'format', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['json', 'csv'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Report retrieved (JSON envelope, or text/csv)', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid year', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function __invoke(WithholdingReportRequest $request): JsonResponse|Response
    {
        $this->authorize('viewTaxReports', Invoice::class);

        $year = $request->integer('year');
        $report = $this->reports->annual($year);

        if ($request->input('format') === 'csv') {
            return response($this->reports->toCsv($report), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"withholdings-{$year}.csv\"",
            ]);
        }

        return $this->successResponse($report, __('tax.messages.withholding_report'));
    }
}
