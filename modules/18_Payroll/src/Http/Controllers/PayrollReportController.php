<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Controllers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Payroll\Http\Requests\PayrollYearExportRequest;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Services\PayrollReportService;
use OpenApi\Attributes as OA;

/**
 * Management exports of the payroll: cost allocation of a run and year-end summaries (data, not official filings).
 */
final class PayrollReportController extends PayrollController
{
    public function __construct(TenantContext $tenantContext, private readonly PayrollReportService $reports)
    {
        parent::__construct($tenantContext);
    }

    /**
     * Allocate the labour cost of a run to projects and cost centres.
     */
    #[OA\Get(
        path: '/api/v1/payroll/runs/{id}/cost-allocation',
        operationId: 'payrollRunCostAllocation',
        description: 'Labour cost of each payslip (gross plus employer contributions) allocated to projects and cost centres in proportion to the approved timesheet hours of the month; without hours the cost goes to the cost centre of the employee or stays unallocated. Amounts are decimal strings.',
        summary: 'Payroll cost allocation',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Allocation', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        ]
    )]
    public function costAllocation(string $id): JsonResponse
    {
        $run = PayrollRun::query()->findOrFail($id);
        $this->authorize('export', PayrollRun::class);

        return response()->json(['data' => $this->reports->costAllocation($run)]);
    }

    /**
     * Year-end summary per employee.
     */
    #[OA\Get(
        path: '/api/v1/payroll/reports/year-end',
        operationId: 'payrollYearEndSummary',
        description: 'Totals of the closed runs of a year per employee (gross, deductions, net, payout, employer contributions, every wage type code). format=csv returns a semicolon separated file. A data export, not an official certificate format (CU, Lohnausweis have their own endpoints).',
        summary: 'Payroll year-end summary',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/PayrollYearRequired'),
            new OA\Parameter(name: 'format', description: 'json (default) or csv', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['json', 'csv'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Summary', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function yearEnd(PayrollYearExportRequest $request): JsonResponse|Response
    {
        $this->authorize('export', PayrollRun::class);
        $year = (int) $request->validated('year');
        $summaries = $this->reports->yearEndSummary($year);

        if ($this->wantsCsv($request)) {
            return response($this->reports->yearEndCsv($year, $summaries), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="payroll-year-end-'.$year.'.csv"',
            ]);
        }

        return response()->json(['data' => ['year' => $year, 'employees' => $summaries]]);
    }

    private function wantsCsv(Request $request): bool
    {
        return $request->query('format') === 'csv';
    }
}
