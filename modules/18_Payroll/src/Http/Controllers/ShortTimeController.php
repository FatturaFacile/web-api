<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Controllers;

use App\Services\Tenancy\TenantContext;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Modules\Payroll\Http\Requests\CalculateShortTimeClaimRequest;
use Modules\Payroll\Http\Requests\ConstructionReportRequest;
use Modules\Payroll\Http\Requests\RecordReimbursementRequest;
use Modules\Payroll\Http\Requests\SaveConstructionParametersRequest;
use Modules\Payroll\Http\Requests\SaveShortTimeCaseRequest;
use Modules\Payroll\Http\Requests\SaveWorkCalendarRequest;
use Modules\Payroll\Http\Resources\PayrollRecordResource;
use Modules\Payroll\Models\CclConstructionParameter;
use Modules\Payroll\Models\CclWorkCalendar;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\ShortTimeCase;
use Modules\Payroll\Models\ShortTimeClaim;
use Modules\Payroll\Services\Construction\ConstructionWageService;
use Modules\Payroll\Services\ShortTime\ShortTimeClaimService;
use OpenApi\Attributes as OA;

/**
 * Short-time work, bad weather, and the construction wage under a collective agreement.
 */
final class ShortTimeController extends PayrollController
{
    private const string SwissOnly = 'Le indennità per lavoro ridotto e intemperie sono disponibili per le aziende svizzere.';

    public function __construct(
        TenantContext $tenantContext,
        private readonly ShortTimeClaimService $claims,
        private readonly ConstructionWageService $construction,
    ) {
        parent::__construct($tenantContext);
    }

    /**
     * List cases.
     */
    #[OA\Get(path: '/api/v1/payroll/short-time/cases', operationId: 'listShortTimeCases', description: 'Return the short-time work and bad-weather cases with their claims.', summary: 'List short-time cases', security: [['bearerAuth' => []]], tags: ['Payroll'], responses: [
        new OA\Response(response: 200, description: 'Cases', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
    ])]
    public function cases(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PayrollRun::class);

        return PayrollRecordResource::collection(ShortTimeCase::query()->with('claims')->withCount('claims')->orderByDesc('starts_on')->gridPaginate(RequestedPerPage::resolve(200)));
    }

    /**
     * Open a case.
     */
    #[OA\Post(path: '/api/v1/payroll/short-time/cases', operationId: 'storeShortTimeCase', description: 'Record a notified case of short-time work or bad weather. The notification itself goes to the cantonal office.', summary: 'Create short-time case', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollSaveShortTimeCaseRequest')), tags: ['Payroll'], responses: [
        new OA\Response(response: 201, description: 'Case', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function storeCase(SaveShortTimeCaseRequest $request): JsonResponse
    {
        $this->authorize('create', PayrollRun::class);
        $this->requireCountry('CH', self::SwissOnly);

        return (new PayrollRecordResource(ShortTimeCase::query()->create($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Calculate the claim of a month.
     */
    #[OA\Post(path: '/api/v1/payroll/short-time/cases/{id}/claims', operationId: 'calculateShortTimeClaim', description: 'Calculate the monthly reimbursement request: lost hours, waiting time, compensation per employee, eligibility, minimum loss and warnings. Recalculating replaces a draft. Hours come from the request or from the working time records.', summary: 'Calculate short-time claim', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollCalculateShortTimeClaimRequest')), tags: ['Payroll'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 201, description: 'Claim', content: new OA\JsonContent(ref: '#/components/schemas/PayrollShortTimeClaim')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function calculateClaim(CalculateShortTimeClaimRequest $request, string $id): JsonResponse
    {
        $this->authorize('calculate', PayrollRun::class);

        $claim = $this->claims->calculate(ShortTimeCase::query()->findOrFail($id), (int) $request->validated('year'), (int) $request->validated('month'), $request->validated('employees'));

        return (new PayrollRecordResource($claim))->response()->setStatusCode(201);
    }

    /**
     * Show a claim.
     */
    #[OA\Get(path: '/api/v1/payroll/short-time/claims/{id}', operationId: 'showShortTimeClaim', description: 'Return a claim with its lines.', summary: 'Show short-time claim', security: [['bearerAuth' => []]], tags: ['Payroll'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 200, description: 'Claim', content: new OA\JsonContent(ref: '#/components/schemas/PayrollShortTimeClaim')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
    ])]
    public function showClaim(string $id): PayrollRecordResource
    {
        $this->authorize('viewAny', PayrollRun::class);

        return new PayrollRecordResource(ShortTimeClaim::query()->with('lines')->findOrFail($id));
    }

    /**
     * Mark a claim as sent.
     */
    #[OA\Post(path: '/api/v1/payroll/short-time/claims/{id}/submit', operationId: 'submitShortTimeClaim', description: 'Record that the claim went to the unemployment fund. It no longer changes.', summary: 'Submit short-time claim', security: [['bearerAuth' => []]], tags: ['Payroll'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 200, description: 'Claim', content: new OA\JsonContent(ref: '#/components/schemas/PayrollShortTimeClaim')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function submitClaim(string $id): PayrollRecordResource
    {
        $this->authorize('calculate', PayrollRun::class);

        return new PayrollRecordResource($this->claims->submit(ShortTimeClaim::query()->findOrFail($id)));
    }

    /**
     * Carry a claim into the payroll inputs.
     */
    #[OA\Post(path: '/api/v1/payroll/short-time/claims/{id}/apply', operationId: 'applyShortTimeClaim', description: 'Create the wage reduction and the compensation as inputs of the month. Refused if the run of the month is closed.', summary: 'Apply short-time claim', security: [['bearerAuth' => []]], tags: ['Payroll'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 200, description: 'Claim', content: new OA\JsonContent(ref: '#/components/schemas/PayrollShortTimeClaim')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PayrollConflict', response: 409),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function applyClaim(string $id): PayrollRecordResource
    {
        $this->authorize('calculate', PayrollRun::class);

        return new PayrollRecordResource($this->claims->applyToPayroll(ShortTimeClaim::query()->findOrFail($id)));
    }

    /**
     * Record the reimbursement.
     */
    #[OA\Post(path: '/api/v1/payroll/short-time/claims/{id}/reimbursement', operationId: 'recordShortTimeReimbursement', description: 'Record what the fund paid.', summary: 'Record short-time reimbursement', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollRecordReimbursementRequest')), tags: ['Payroll'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 200, description: 'Claim', content: new OA\JsonContent(ref: '#/components/schemas/PayrollShortTimeClaim')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function recordReimbursement(RecordReimbursementRequest $request, string $id): PayrollRecordResource
    {
        $this->authorize('calculate', PayrollRun::class);

        return new PayrollRecordResource($this->claims->recordReimbursement(ShortTimeClaim::query()->findOrFail($id), (string) $request->validated('amount'), $request->validated('paid_on')));
    }

    /**
     * The figures of a claim as a file.
     */
    #[OA\Get(path: '/api/v1/payroll/short-time/claims/{id}/export', operationId: 'exportShortTimeClaim', description: 'CSV with the figures for the forms of the fund. It is not the official form.', summary: 'Export short-time claim', security: [['bearerAuth' => []]], tags: ['Payroll'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string'))),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
    ])]
    public function exportClaim(string $id): HttpResponse
    {
        $this->authorize('export', PayrollRun::class);

        $file = $this->claims->file(ShortTimeClaim::query()->findOrFail($id));

        return response($file->content, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.Str::ascii($file->fileName).'"', 'X-Filing-Summary' => rawurlencode($file->summary)]);
    }

    /**
     * Add a version of the construction parameters.
     */
    #[OA\Post(path: '/api/v1/payroll/construction/parameters', operationId: 'storeConstructionParameters', description: 'Store the figures of a collective agreement from a date, with their source: annual hours, start of the accounting year, minimum monthly and hourly wage per qualification and zone. Nothing is preloaded.', summary: 'Create construction parameters', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollSaveConstructionParametersRequest')), tags: ['Payroll'], responses: [
        new OA\Response(response: 201, description: 'Parameters', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function storeConstructionParameters(SaveConstructionParametersRequest $request): JsonResponse
    {
        $this->authorize('create', PayrollRun::class);

        return (new PayrollRecordResource(CclConstructionParameter::query()->create($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Save calendar months.
     */
    #[OA\Put(path: '/api/v1/payroll/construction/calendar', operationId: 'saveConstructionCalendar', description: 'Set the hours the company calendar plans for months of an agreement.', summary: 'Save construction calendar', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollSaveWorkCalendarRequest')), tags: ['Payroll'], responses: [
        new OA\Response(response: 200, description: 'Saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function saveCalendar(SaveWorkCalendarRequest $request): JsonResponse
    {
        $this->authorize('create', PayrollRun::class);

        foreach ($request->validated('months') as $month) {
            CclWorkCalendar::query()->updateOrCreate(
                ['agreement_code' => $request->validated('agreement_code'), 'year' => $month['year'], 'month' => $month['month']],
                ['planned_hours' => $month['planned_hours']],
            );
        }

        return response()->json(['data' => ['saved' => count($request->validated('months'))]]);
    }

    /**
     * Monthly wage and hours of an employee.
     */
    #[OA\Get(path: '/api/v1/payroll/construction/hours', operationId: 'constructionHours', description: 'Return the constant monthly wage from the hourly wage and the annual hours of the agreement, and the worked hours against the calendar in the accounting year.', summary: 'Construction hours', security: [['bearerAuth' => []]], tags: ['Payroll'], parameters: [
        new OA\Parameter(name: 'agreement_code', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'accounting_year', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'employee_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Hours', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function constructionHours(ConstructionReportRequest $request): JsonResponse
    {
        $this->authorize('viewAny', PayrollRun::class);

        $employee = $this->employee((string) $request->validated('employee_id'));
        $agreementCode = (string) $request->validated('agreement_code');
        $accountingYear = (int) $request->validated('accounting_year');
        $profile = PayrollEmployeeProfile::query()->where('employee_id', $employee->id)->first();
        $parameters = $this->construction->parameters($agreementCode, sprintf('%d-12-31', $accountingYear + 1));

        return response()->json(['data' => [
            ...$this->construction->hoursBalance($employee, $agreementCode, $accountingYear),
            'hourly_wage' => (string) ($profile->hourly_wage ?? '0'),
            'constant_monthly_wage' => $this->construction->monthlyWage((string) ($profile->hourly_wage ?? '0'), (string) $parameters->annual_hours),
        ]]);
    }

    /**
     * Report for the joint commission.
     */
    #[OA\Get(path: '/api/v1/payroll/construction/joint-commission-report', operationId: 'constructionJointCommissionReport', description: 'Annual report of an agreement from the closed runs: people, hours, pay and average pay per qualification and zone, against the minimum wages.', summary: 'Joint commission report', security: [['bearerAuth' => []]], tags: ['Payroll'], parameters: [
        new OA\Parameter(name: 'agreement_code', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'accounting_year', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
    ], responses: [
        new OA\Response(response: 200, description: 'Report', content: new OA\JsonContent(ref: '#/components/schemas/PayrollJointCommissionReport')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function jointCommissionReport(ConstructionReportRequest $request): JsonResponse
    {
        $this->authorize('export', PayrollRun::class);

        return response()->json(['data' => $this->construction->jointCommissionReport((string) $request->validated('agreement_code'), (int) $request->validated('accounting_year'))]);
    }

    /**
     * The report as a file.
     */
    #[OA\Get(path: '/api/v1/payroll/exports/joint-commission-report', operationId: 'exportJointCommissionReport', description: 'CSV of the annual report for the joint commission. The layout belongs to this application.', summary: 'Export joint commission report', security: [['bearerAuth' => []]], tags: ['Payroll'], parameters: [
        new OA\Parameter(name: 'agreement_code', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'accounting_year', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
    ], responses: [
        new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string'))),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function exportJointCommissionReport(ConstructionReportRequest $request): HttpResponse
    {
        $this->authorize('export', PayrollRun::class);

        $file = $this->construction->jointCommissionFile((string) $request->validated('agreement_code'), (int) $request->validated('accounting_year'));

        return response($file->content, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.Str::ascii($file->fileName).'"', 'X-Filing-Summary' => rawurlencode($file->summary)]);
    }
}
