<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Controllers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Modules\Payroll\Http\Requests\ChildAllowanceOverviewRequest;
use Modules\Payroll\Http\Requests\CreatePayrollPaymentsRequest;
use Modules\Payroll\Http\Requests\LabourCostReportRequest;
use Modules\Payroll\Http\Requests\OpenPayslipLinkRequest;
use Modules\Payroll\Http\Requests\PayrollYearExportRequest;
use Modules\Payroll\Http\Requests\SimulateHiringRequest;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Services\Extras\ChildAllowanceService;
use Modules\Payroll\Services\Extras\HiringSimulationService;
use Modules\Payroll\Services\Extras\LabourCostReportService;
use Modules\Payroll\Services\Extras\PayrollPaymentFileService;
use Modules\Payroll\Services\Extras\PayslipDeliveryService;
use Modules\Payroll\Swissdec\SalaryDeclarationBuilder;
use Modules\Payroll\Swissdec\SalaryDeclarationTransmitter;
use Modules\Payroll\Swissdec\SwissdecCertificationStatus;
use OpenApi\Attributes as OA;

/**
 * Salary declarations in ELM structure, hiring simulation, payslip delivery, payment files, labour cost and child
 * allowances.
 */
final class PayrollExtrasController extends PayrollController
{
    private const string SwissOnly = 'La dichiarazione dei salari è disponibile per le aziende svizzere.';

    public function __construct(
        TenantContext $tenantContext,
        private readonly SalaryDeclarationBuilder $declarations,
        private readonly SalaryDeclarationTransmitter $transmitter,
        private readonly HiringSimulationService $simulations,
        private readonly PayslipDeliveryService $deliveries,
        private readonly PayrollPaymentFileService $payments,
        private readonly LabourCostReportService $labourCost,
        private readonly ChildAllowanceService $childAllowances,
    ) {
        parent::__construct($tenantContext);
    }

    /**
     * The certification status.
     */
    #[OA\Get(path: '/api/v1/payroll/swissdec/status', operationId: 'swissdecStatus', description: 'State plainly that the application is not Swissdec certified, what the data structure is based on and what is missing to reach certification.', summary: 'Swissdec status', security: [['bearerAuth' => []]], tags: ['Payroll'], responses: [
        new OA\Response(response: 200, description: 'Status', content: new OA\JsonContent(ref: '#/components/schemas/PayrollSwissdecStatus')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
    ])]
    public function swissdecStatus(): JsonResponse
    {
        $this->authorize('viewAny', PayrollRun::class);

        return response()->json(['data' => SwissdecCertificationStatus::toArray()]);
    }

    /**
     * The salary declaration of a year.
     */
    #[OA\Get(path: '/api/v1/payroll/swissdec/declaration', operationId: 'swissdecDeclaration', description: 'Swiss companies: salary declaration of a year from the closed runs, as a data structure named after the elements of the public ELM 5.0 field overview. Not a Swissdec message: not validated against an XSD, limited to the AHV and tax at source domains, not certified.', summary: 'ELM-structured salary declaration', security: [['bearerAuth' => []]], tags: ['Payroll'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollYearRequired')], responses: [
        new OA\Response(response: 200, description: 'Declaration data', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function swissdecDeclaration(PayrollYearExportRequest $request): JsonResponse
    {
        $this->authorize('export', PayrollRun::class);
        $this->requireCountry('CH', self::SwissOnly);

        return response()->json(['data' => $this->declarations->build($this->tenant(), (int) $request->validated('year'))]);
    }

    /**
     * What is missing in the data of a year.
     */
    #[OA\Get(path: '/api/v1/payroll/swissdec/readiness', operationId: 'swissdecReadiness', description: 'List the data missing or wrong in the declaration of a year, for the company and per person, including the check digit of the AHV number.', summary: 'Salary declaration readiness', security: [['bearerAuth' => []]], tags: ['Payroll'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollYearRequired')], responses: [
        new OA\Response(response: 200, description: 'Readiness', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function swissdecReadiness(PayrollYearExportRequest $request): JsonResponse
    {
        $this->authorize('export', PayrollRun::class);
        $this->requireCountry('CH', self::SwissOnly);

        return response()->json(['data' => $this->declarations->readiness($this->tenant(), (int) $request->validated('year'))]);
    }

    /**
     * Send a declaration: not available.
     */
    #[OA\Post(path: '/api/v1/payroll/swissdec/transmissions', operationId: 'swissdecTransmit', description: 'Contract of the future transmission. The only driver is a stub that sends nothing and answers 501, because the application is not certified.', summary: 'Transmit salary declaration (not available)', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['year'], properties: [new OA\Property(property: 'year', type: 'integer')], type: 'object')), tags: ['Payroll'], responses: [
        new OA\Response(response: 501, description: 'Transmission not available', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function swissdecTransmit(PayrollYearExportRequest $request): JsonResponse
    {
        $this->authorize('calculate', PayrollRun::class);
        $this->requireCountry('CH', self::SwissOnly);
        $receipt = $this->transmitter->transmit($this->declarations->build($this->tenant(), (int) $request->validated('year')));

        return response()->json(['data' => ['declaration_id' => $receipt->declarationId, 'received_at' => $receipt->receivedAt]]);
    }

    /**
     * Simulate a hiring.
     */
    #[OA\Post(path: '/api/v1/payroll/hiring-simulations', operationId: 'simulateHiring', description: 'Gross-to-net with annual_gross or net-to-gross with target_net_monthly for someone not hired yet, on the preview of the country of the company. Nothing is saved. It is an estimate, not a payslip.', summary: 'Simulate hiring', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollSimulateHiringRequest')), tags: ['Payroll'], responses: [
        new OA\Response(response: 200, description: 'Simulation', content: new OA\JsonContent(ref: '#/components/schemas/PayrollHiringSimulation')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function simulateHiring(SimulateHiringRequest $request): JsonResponse
    {
        $this->authorize('simulate', PayrollRun::class);

        return response()->json(['data' => $this->simulations->simulate($this->tenant(), $request->validated())]);
    }

    /**
     * Tell the employees that their payslips are ready.
     */
    #[OA\Post(path: '/api/v1/payroll/runs/{id}/deliver-payslips', operationId: 'deliverPayslips', description: 'For a closed run, mail each employee a personal link. The mail holds no figure and no attachment; opening the link asks for the date of birth. Answers who was skipped and why.', summary: 'Deliver payslips by mail', security: [['bearerAuth' => []]], tags: ['Payroll'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 200, description: 'Outcome', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function deliverPayslips(string $id): JsonResponse
    {
        $run = PayrollRun::query()->findOrFail($id);
        $this->authorize('finalize', $run);

        return response()->json(['data' => $this->deliveries->deliver($run)]);
    }

    /**
     * Open a payslip link.
     */
    #[OA\Post(path: '/api/v1/public/payslips/{token}', operationId: 'openPayslipLink', description: 'Public and rate limited: the PDF of a mailed link, against the date of birth of the employee. Every failure answers 404; a few wrong dates lock the link.', summary: 'Open payslip link', requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollOpenPayslipLinkRequest')), tags: ['Payroll'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [
        new OA\Response(response: 200, description: 'PDF document', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary'))),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function openPayslipLink(OpenPayslipLinkRequest $request, string $token): HttpResponse
    {
        $document = $this->deliveries->open($token, (string) $request->validated('birth_date'));

        return $this->pdfResponse($document['content'], $document['file_name']);
    }

    /**
     * Create the payment files of a run.
     */
    #[OA\Post(path: '/api/v1/payroll/runs/{id}/payment-batches', operationId: 'createPayrollPaymentBatches', description: 'For a closed run, create one Treasury batch per debtor account from the payouts of the payslips. Allocations choose the account by employee or department; an allocation with neither is the default. The batches go through approval and pain.001 export in Treasury.', summary: 'Create payroll payment batches', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollCreatePaymentsRequest')), tags: ['Payroll'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 201, description: 'Batches', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function createPaymentBatches(CreatePayrollPaymentsRequest $request, string $id): JsonResponse
    {
        $run = PayrollRun::query()->findOrFail($id);
        $this->authorize('finalize', $run);

        $batches = $this->payments->createBatches($run, $this->actor($request), (string) $this->tenant()->default_currency, $request->validated('allocations'));

        return response()->json(['data' => $batches->map(static fn ($batch): array => [
            'id' => $batch->id,
            'bank_account_id' => $batch->bank_account_id,
            'batch_reference' => $batch->batch_reference,
            'total_amount' => (string) $batch->total_amount,
            'item_count' => $batch->item_count,
            'status' => $batch->status,
        ])->all()], 201);
    }

    /**
     * Hours and labour cost.
     */
    #[OA\Get(path: '/api/v1/payroll/reports/labour-cost', operationId: 'labourCostReport', description: 'Hours and labour cost of the closed runs by department or site (business unit), with the cost per hour.', summary: 'Labour cost report', security: [['bearerAuth' => []]], tags: ['Payroll'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/PayrollYearRequired'),
        new OA\Parameter(ref: '#/components/parameters/PayrollMonthFilter'),
        new OA\Parameter(name: 'group_by', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['department', 'site'])),
    ], responses: [
        new OA\Response(response: 200, description: 'Report', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function labourCost(LabourCostReportRequest $request): JsonResponse
    {
        $this->authorize('export', PayrollRun::class);

        return response()->json(['data' => $this->labourCost->report((int) $request->validated('year'), $request->validated('month') === null ? null : (int) $request->validated('month'), (string) $request->validated('group_by', 'department'))]);
    }

    /**
     * The allowance of each child.
     */
    #[OA\Get(path: '/api/v1/payroll/children/allowances', operationId: 'childAllowanceOverview', description: 'Swiss companies: for each child record, the family allowance it gives in a month, with the rule the payroll engine applies.', summary: 'Child allowances', security: [['bearerAuth' => []]], tags: ['Payroll'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/PayrollYearRequired'),
        new OA\Parameter(ref: '#/components/parameters/PayrollMonthRequired'),
        new OA\Parameter(ref: '#/components/parameters/PayrollEmployeeFilter'),
    ], responses: [
        new OA\Response(response: 200, description: 'Overview', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function childAllowances(ChildAllowanceOverviewRequest $request): JsonResponse
    {
        $this->authorize('viewAny', PayrollRun::class);
        $this->requireCountry('CH', 'Gli assegni familiari sono disponibili per le aziende svizzere.');

        return response()->json(['data' => $this->childAllowances->overview((int) $request->validated('year'), (int) $request->validated('month'), $request->validated('employee_id'))]);
    }
}
