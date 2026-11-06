<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Controllers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Modules\Payroll\Http\Requests\ListEmployeeDocumentsRequest;
use Modules\Payroll\Http\Resources\PayrollRecordResource;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Policies\EmployeeDocumentPolicy;
use Modules\Payroll\Services\PayrollDocumentService;
use OpenApi\Attributes as OA;

/**
 * Payslips: whoever can read the payroll sees all of them, an employee only the own ones.
 */
final class PayslipController extends PayrollController
{
    public function __construct(
        TenantContext $tenantContext,
        private readonly PayrollDocumentService $documents,
        private readonly EmployeeDocumentPolicy $access,
    ) {
        parent::__construct($tenantContext);
    }

    /**
     * List payslips.
     */
    #[OA\Get(
        path: '/api/v1/payroll/payslips',
        operationId: 'listPayslips',
        description: 'Return the payslips of the company, or only the own ones for an employee without payroll access. employee_id filters by employee, year and month by period.',
        summary: 'List payslips',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/PayrollEmployeeFilter'),
            new OA\Parameter(ref: '#/components/parameters/PayrollYearFilter'),
            new OA\Parameter(ref: '#/components/parameters/PayrollMonthFilter'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Payslips', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function index(ListEmployeeDocumentsRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Payslip::class);

        $employeeId = $request->validated('employee_id');

        if ($employeeId !== null) {
            $this->authorize('viewEmployee', [Payslip::class, (string) $employeeId]);
        }

        $actor = $this->actor($request);
        $payslips = Payslip::query()
            ->when($employeeId !== null, static fn ($query) => $query->where('employee_id', $employeeId))
            ->when(! $this->access->viewAllEmployees($actor), fn ($query) => $query->whereIn('employee_id', $this->access->ownEmployeeIds($actor)))
            ->when($request->validated('year') !== null, static fn ($query) => $query->where('year', (int) $request->validated('year')))
            ->when($request->validated('month') !== null, static fn ($query) => $query->where('month', (int) $request->validated('month')))
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderBy('employee_name')
            ->paginate((int) $request->validated('per_page', 50));

        return PayrollRecordResource::collection($payslips);
    }

    /**
     * Show a payslip with its lines.
     */
    #[OA\Get(
        path: '/api/v1/payroll/payslips/{id}',
        operationId: 'showPayslip',
        description: 'Return a payslip with its lines.',
        summary: 'Show payslip',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Payslip', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        ]
    )]
    public function show(Request $request, string $id): PayrollRecordResource
    {
        $payslip = Payslip::query()->findOrFail($id);
        $this->authorize('view', $payslip);

        return new PayrollRecordResource($payslip->load('lines'));
    }

    /**
     * Print a payslip.
     */
    #[OA\Get(
        path: '/api/v1/payroll/payslips/{id}/pdf',
        operationId: 'downloadPayslipPdf',
        description: 'Return the payslip as a PDF document.',
        summary: 'Download payslip',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'PDF document', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        ]
    )]
    public function pdf(string $id): HttpResponse
    {
        $payslip = Payslip::query()->findOrFail($id);
        $this->authorize('view', $payslip);

        return $this->pdfResponse(
            $this->documents->payslip($payslip, $this->tenant()),
            sprintf('Busta_paga_%d-%02d_%s.pdf', $payslip->year, $payslip->month, str_replace(' ', '_', $payslip->employee_name)),
        );
    }
}
