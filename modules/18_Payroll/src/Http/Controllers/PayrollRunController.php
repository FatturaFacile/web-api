<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Controllers;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Modules\Payroll\Calculation\PayrollPreview;
use Modules\Payroll\Http\Requests\CalculatePayrollRunRequest;
use Modules\Payroll\Http\Requests\PreviewPayrollRequest;
use Modules\Payroll\Http\Requests\ReopenPayrollRunRequest;
use Modules\Payroll\Http\Requests\SimulatePayrollRequest;
use Modules\Payroll\Http\Resources\PayrollRunResource;
use Modules\Payroll\Http\Resources\PayrollSimulatedPayslipResource;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Services\PayrollCalculator;
use Modules\Payroll\Services\PayrollDocumentService;
use Modules\Payroll\Services\PayrollRunService;
use OpenApi\Attributes as OA;

/**
 * Monthly runs: calculation, simulation, closing and explicit reopening.
 */
final class PayrollRunController extends PayrollController
{
    public function __construct(
        TenantContext $tenantContext,
        private readonly PayrollRunService $runs,
        private readonly PayrollCalculator $calculator,
        private readonly PayrollDocumentService $documents,
    ) {
        parent::__construct($tenantContext);
    }

    /**
     * List the monthly runs of the company.
     */
    #[OA\Get(
        path: '/api/v1/payroll/runs',
        operationId: 'listPayrollRuns',
        description: 'Return the monthly payroll runs of the active company, newest first.',
        summary: 'List payroll runs',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollYearFilter')],
        responses: [
            new OA\Response(response: 200, description: 'Payroll runs', content: new OA\JsonContent(ref: '#/components/schemas/PayrollRunPage')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PayrollRun::class);

        $runs = PayrollRun::query()
            ->when($request->integer('year') > 0, static fn ($query) => $query->where('year', $request->integer('year')))
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->paginate($this->perPage($request, 24));

        return PayrollRunResource::collection($runs);
    }

    /**
     * Show a run with its payslips and its history.
     */
    #[OA\Get(
        path: '/api/v1/payroll/runs/{id}',
        operationId: 'showPayrollRun',
        description: 'Return a run with its payslips and the history of calculations, closings and reopenings.',
        summary: 'Show payroll run',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Payroll run', content: new OA\JsonContent(ref: '#/components/schemas/PayrollRunEnvelope')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        ]
    )]
    public function show(string $id): PayrollRunResource
    {
        $run = PayrollRun::query()->findOrFail($id);
        $this->authorize('view', $run);

        return new PayrollRunResource($run->load('payslips', 'events'));
    }

    /**
     * Calculate or recalculate the run of a month.
     */
    #[OA\Post(
        path: '/api/v1/payroll/runs/calculate',
        operationId: 'calculatePayrollRun',
        description: 'Calculate the payslips of every employee of the month with the engine of the country of the company. A draft run is recalculated; a closed run has to be reopened first.',
        summary: 'Calculate payroll run',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollCalculateRequest')),
        tags: ['Payroll'],
        responses: [
            new OA\Response(response: 201, description: 'Run calculated', content: new OA\JsonContent(ref: '#/components/schemas/PayrollRunEnvelope')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollConflict', response: 409),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function calculate(CalculatePayrollRunRequest $request): JsonResponse
    {
        $this->authorize('calculate', PayrollRun::class);

        $run = $this->runs->calculate(
            $this->tenant(),
            $this->actor($request),
            (int) $request->validated('year'),
            (int) $request->validated('month'),
            $request->validated('payment_date'),
        );

        return (new PayrollRunResource($run->refresh()))->response()->setStatusCode(201);
    }

    /**
     * Run the monthly engine without saving anything.
     */
    #[OA\Post(
        path: '/api/v1/payroll/runs/simulate',
        operationId: 'simulatePayrollRun',
        description: 'Run the monthly engine for the whole company, or for one employee with employee_id, and return the payslips without saving them.',
        summary: 'Simulate payroll run',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollSimulateRequest')),
        tags: ['Payroll'],
        responses: [
            new OA\Response(response: 200, description: 'Simulated payslips', content: new OA\JsonContent(ref: '#/components/schemas/PayrollSimulationResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function simulate(SimulatePayrollRequest $request): JsonResponse
    {
        $this->authorize('simulate', PayrollRun::class);

        $employeeId = $request->validated('employee_id');

        if ($employeeId !== null) {
            $this->employee((string) $employeeId);
        }

        $results = $this->calculator->calculate($this->tenant(), (int) $request->validated('year'), (int) $request->validated('month'), $employeeId);

        return PayrollSimulatedPayslipResource::collection(array_values($results))->response();
    }

    /**
     * Estimate the monthly pay of an employee from the annual salary.
     */
    #[OA\Post(
        path: '/api/v1/payroll/runs/preview',
        operationId: 'previewEmployeePayroll',
        description: 'Quick estimate of the monthly pay of an employee from the annual salary, without inputs, absences or year-to-date values. Nothing is saved.',
        summary: 'Preview employee payroll',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollPreviewRequest')),
        tags: ['Payroll'],
        responses: [
            new OA\Response(response: 200, description: 'Payroll preview', content: new OA\JsonContent(ref: '#/components/schemas/PayrollPreviewResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function preview(PreviewPayrollRequest $request): JsonResponse
    {
        $this->authorize('simulate', PayrollRun::class);

        $employee = $this->employee((string) $request->validated('employee_id'));
        $target = $request->validated('target_month') === null ? CarbonImmutable::now() : CarbonImmutable::parse((string) $request->validated('target_month'));

        return response()->json(['data' => self::presentPreview($this->calculator->preview($this->tenant(), $employee, $target->year, $target->month))]);
    }

    /**
     * Approve a calculated run.
     */
    #[OA\Post(
        path: '/api/v1/payroll/runs/{id}/approve',
        operationId: 'approvePayrollRun',
        description: 'Approve a calculated run (status Calculated → Approved). An approved run cannot be recalculated until the approval is revoked with the reopen action; only an approved run can be closed.',
        summary: 'Approve payroll run',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Run approved', content: new OA\JsonContent(ref: '#/components/schemas/PayrollRunEnvelope')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PayrollConflict', response: 409),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function approve(Request $request, string $id): PayrollRunResource
    {
        $run = PayrollRun::query()->findOrFail($id);
        $this->authorize('approve', $run);

        return new PayrollRunResource($this->runs->approve($run, $this->actor($request)));
    }

    /**
     * Close a run.
     */
    #[OA\Post(
        path: '/api/v1/payroll/runs/{id}/finalize',
        operationId: 'finalizePayrollRun',
        description: 'Close an approved run: from now on it and its payslips are immutable. Publishes the accounting.payroll.run_finalized domain event.',
        summary: 'Close payroll run',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Run closed', content: new OA\JsonContent(ref: '#/components/schemas/PayrollRunEnvelope')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PayrollConflict', response: 409),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function finalize(Request $request, string $id): PayrollRunResource
    {
        $run = PayrollRun::query()->findOrFail($id);
        $this->authorize('finalize', $run);

        return new PayrollRunResource($this->runs->finalize($run, $this->actor($request)));
    }

    /**
     * Reopen the latest closed run.
     */
    #[OA\Post(
        path: '/api/v1/payroll/runs/{id}/reopen',
        operationId: 'reopenPayrollRun',
        description: 'Revoke the approval of an approved run (back to Calculated), or bring the latest closed run back to Draft. The reason is mandatory and stays in the immutable history of the run. Publishes the accounting.payroll.run_reopened domain event.',
        summary: 'Reopen payroll run',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollReopenRequest')),
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Run reopened', content: new OA\JsonContent(ref: '#/components/schemas/PayrollRunEnvelope')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function reopen(ReopenPayrollRunRequest $request, string $id): PayrollRunResource
    {
        $run = PayrollRun::query()->findOrFail($id);
        $this->authorize('reopen', $run);

        return new PayrollRunResource($this->runs->reopen($run, $this->actor($request), (string) $request->validated('reason')));
    }

    /**
     * Print the labour book of a run.
     */
    #[OA\Get(
        path: '/api/v1/payroll/runs/{id}/labour-book',
        operationId: 'downloadPayrollLabourBook',
        description: 'Italian companies: monthly print of the Libro Unico del Lavoro (pay section, line detail and attendance section). Numbering, sealing and retention remain with the company.',
        summary: 'Download labour book',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'PDF document', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function labourBook(string $id): HttpResponse
    {
        $run = PayrollRun::query()->findOrFail($id);
        $this->authorize('export', $run);
        $this->requireCountry('IT', 'Il Libro Unico del Lavoro è disponibile per le aziende italiane.');

        return $this->pdfResponse($this->documents->labourBook($run, $this->tenant()), sprintf('LUL_%d-%02d.pdf', $run->year, $run->month));
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentPreview(PayrollPreview $preview): array
    {
        return [
            'country_code' => $preview->countryCode,
            'currency' => $preview->currency,
            'gross_monthly_salary' => $preview->grossMonthlySalary,
            'total_deductions' => $preview->totalDeductions,
            'net_monthly_salary' => $preview->netMonthlySalary,
            'employer_total_cost' => $preview->employerTotalCost,
            'deduction_breakdown' => $preview->deductionBreakdown,
            'compliance_notes' => $preview->complianceNotes,
        ];
    }
}
