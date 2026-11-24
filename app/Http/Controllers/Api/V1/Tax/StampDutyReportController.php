<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tax;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tax\StampDutyReportRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Services\Invoicing\Tax\StampDutyReportService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * Quarterly virtual stamp duty report of the issued e-invoices, with the F24 payment data.
 */
class StampDutyReportController extends Controller
{
    use ApiResponse, AuthorizesRequests;

    public function __construct(private readonly StampDutyReportService $reports) {}

    #[OA\Get(
        path: '/api/v1/tax/stamp-duty/quarterly',
        operationId: 'getStampDutyQuarterlyReport',
        description: 'Invoices with virtual stamp duty in a quarter (by invoice date, credit notes and pro-forma excluded): count, total, F24 tributo code, due date and deferrable flag. `format=csv` downloads a CSV.',
        summary: 'Quarterly stamp duty report',
        security: [['bearerAuth' => []]],
        tags: ['Tax'],
        parameters: [
            new OA\Parameter(name: 'year', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 2026)),
            new OA\Parameter(name: 'quarter', in: 'query', required: true, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 4, example: 1)),
            new OA\Parameter(name: 'format', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['json', 'csv'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Report retrieved (JSON envelope, or text/csv)', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid year or quarter', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function __invoke(StampDutyReportRequest $request): JsonResponse|Response
    {
        $this->authorize('viewTaxReports', Invoice::class);

        $year = $request->integer('year');
        $quarter = $request->integer('quarter');
        $report = $this->reports->quarterly($year, $quarter);

        if ($request->input('format') === 'csv') {
            return response($this->reports->toCsv($report), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"stamp-duty-{$year}-Q{$quarter}.csv\"",
            ]);
        }

        return $this->successResponse($report, __('tax.messages.stamp_duty_report'));
    }
}
