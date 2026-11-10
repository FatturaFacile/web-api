<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Controllers;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Modules\Payroll\Exports\GeneratedFile;
use Modules\Payroll\Http\Requests\PayrollYearExportRequest;
use Modules\Payroll\Http\Requests\SaveFrontierYearRequest;
use Modules\Payroll\Http\Requests\SaveLegalParametersRequest;
use Modules\Payroll\Http\Requests\ValidateSalaryDeclarationRequest;
use Modules\Payroll\Http\Requests\WithholdingStatementRequest;
use Modules\Payroll\Http\Resources\PayrollRecordResource;
use Modules\Payroll\Models\PayrollFrontierYear;
use Modules\Payroll\Models\PayrollLegalParameter;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Services\Frontier\FrontierWorkerService;
use Modules\Payroll\Services\SwissPermitComplianceService;
use Modules\Payroll\Services\Withholding\WithholdingStatementService;
use Modules\Payroll\Swissdec\SalaryDeclarationBuilder;
use Modules\Payroll\Swissdec\SwissdecElmXmlSerializer;
use Modules\Payroll\Swissdec\SwissdecXsdValidator;
use OpenApi\Attributes as OA;

/**
 * Legal parameters by topic, withholding tax statements, frontier workers, Swiss work permits, and Swissdec ELM XML validation.
 */
final class PayrollComplianceController extends PayrollController
{
    /** @var list<string> */
    private const array LegalTopics = ['frontier_italy', 'short_time_work', 'bad_weather', 'withholding_tax_statement'];

    public function __construct(
        TenantContext $tenantContext,
        private readonly WithholdingStatementService $statements,
        private readonly FrontierWorkerService $frontier,
        private readonly SwissPermitComplianceService $permits = new SwissPermitComplianceService,
        private readonly SalaryDeclarationBuilder $declarations = new SalaryDeclarationBuilder,
        private readonly SwissdecElmXmlSerializer $xmlSerializer = new SwissdecElmXmlSerializer,
        private readonly SwissdecXsdValidator $xsdValidator = new SwissdecXsdValidator,
    ) {
        parent::__construct($tenantContext);
    }

    /**
     * List legal parameters.
     */
    #[OA\Get(path: '/api/v1/payroll/legal-parameters', operationId: 'listPayrollLegalParameters', description: 'Return the per-year legal parameters by topic with their source: generic rows and those of the company.', summary: 'List legal parameters', security: [['bearerAuth' => []]], tags: ['Payroll'], responses: [
        new OA\Response(response: 200, description: 'Parameters', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
    ])]
    public function legalParameters(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PayrollRun::class);

        return PayrollRecordResource::collection(PayrollLegalParameter::query()->orderBy('topic')->orderByDesc('year')->get());
    }

    /**
     * Set the parameters of a topic for a year.
     */
    #[OA\Put(path: '/api/v1/payroll/legal-parameters/{topic}/{year}', operationId: 'savePayrollLegalParameters', description: 'Set company values for a topic and year, stating their source. They override the generic row for this company.', summary: 'Save legal parameters', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollSaveLegalParametersRequest')), tags: ['Payroll'], parameters: [
        new OA\Parameter(name: 'topic', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['frontier_italy', 'short_time_work', 'bad_weather', 'withholding_tax_statement'])),
        new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
    ], responses: [
        new OA\Response(response: 200, description: 'Parameters', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function saveLegalParameters(SaveLegalParametersRequest $request, string $topic, int $year): PayrollRecordResource
    {
        $this->authorize('create', PayrollRun::class);
        abort_unless(in_array($topic, self::LegalTopics, true), 404);

        $row = PayrollLegalParameter::query()->whereNotNull('tenant_id')->where('topic', $topic)->where('year', $year)->first()
            ?? new PayrollLegalParameter(['topic' => $topic, 'year' => $year]);
        $row->fill($request->validated())->save();

        return $this->savedRecord($row);
    }

    /**
     * The withholding tax statement of a period.
     */
    #[OA\Get(path: '/api/v1/payroll/withholding-statements', operationId: 'showWithholdingStatement', description: 'Swiss companies: withholding tax data by canton for a month, quarter or year from the closed runs, with corrections of periods closed earlier, the missing data and the collection commission. format_status says that no official IFonte upload format is public: this is a data layer.', summary: 'Withholding tax statement', security: [['bearerAuth' => []]], tags: ['Payroll'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/PayrollYearRequired'),
        new OA\Parameter(name: 'period_type', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['monthly', 'quarterly', 'annual'])),
        new OA\Parameter(name: 'period', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'canton', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
    ], responses: [
        new OA\Response(response: 200, description: 'Statement data', content: new OA\JsonContent(ref: '#/components/schemas/PayrollWithholdingStatementResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function withholdingStatement(WithholdingStatementRequest $request): JsonResponse
    {
        $this->authorize('export', PayrollRun::class);
        $this->requireCountry('CH', "L'imposta alla fonte è disponibile per le aziende svizzere.");

        return response()->json(['data' => $this->statements->statement(
            (int) $request->validated('year'),
            (string) $request->validated('period_type'),
            (int) $request->validated('period'),
            $request->validated('canton'),
        )]);
    }

    /**
     * Close a statement.
     */
    #[OA\Post(path: '/api/v1/payroll/withholding-statements', operationId: 'closeWithholdingStatement', description: 'Record a period of a canton as declared. It never changes afterwards: later differences become corrections in the next statement.', summary: 'Close withholding tax statement', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollWithholdingStatementRequest')), tags: ['Payroll'], responses: [
        new OA\Response(response: 201, description: 'Closed statement', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function closeWithholdingStatement(WithholdingStatementRequest $request): JsonResponse
    {
        $this->authorize('calculate', PayrollRun::class);
        $this->requireCountry('CH', "L'imposta alla fonte è disponibile per le aziende svizzere.");

        $statement = $this->statements->close(
            $this->actor($request),
            (int) $request->validated('year'),
            (string) $request->validated('period_type'),
            (int) $request->validated('period'),
            (string) $request->validated('canton'),
        );

        return (new PayrollRecordResource($statement))->response()->setStatusCode(201);
    }

    /**
     * The statement as a work file.
     */
    #[OA\Get(path: '/api/v1/payroll/exports/withholding-statement', operationId: 'exportWithholdingStatement', description: 'CSV of the statement of a canton. The layout belongs to this application and is not an official file: it serves the manual entry in IFonte or a certified channel.', summary: 'Export withholding tax statement', security: [['bearerAuth' => []]], tags: ['Payroll'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/PayrollYearRequired'),
        new OA\Parameter(name: 'period_type', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['monthly', 'quarterly', 'annual'])),
        new OA\Parameter(name: 'period', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'canton', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
    ], responses: [
        new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string'))),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function exportWithholdingStatement(WithholdingStatementRequest $request): HttpResponse
    {
        $this->authorize('export', PayrollRun::class);
        $this->requireCountry('CH', "L'imposta alla fonte è disponibile per le aziende svizzere.");

        return $this->csvResponse($this->statements->file(
            (int) $request->validated('year'),
            (string) $request->validated('period_type'),
            (int) $request->validated('period'),
            (string) $request->validated('canton'),
        ));
    }

    /**
     * Frontier workers of a year.
     */
    #[OA\Get(path: '/api/v1/payroll/frontier-workers', operationId: 'listFrontierWorkers', description: 'Assess the workers resident in Italy against the Switzerland-Italy agreement with the parameters of the year: regime, tariff check, nights away, exchange of information and missing data.', summary: 'Frontier workers', security: [['bearerAuth' => []]], tags: ['Payroll'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollYearRequired')], responses: [
        new OA\Response(response: 200, description: 'Assessment', content: new OA\JsonContent(ref: '#/components/schemas/PayrollFrontierOverview')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function frontierWorkers(PayrollYearExportRequest $request): JsonResponse
    {
        $this->authorize('viewAny', PayrollRun::class);

        return response()->json(['data' => $this->frontier->overview((int) $request->validated('year'))]);
    }

    /**
     * Record the nights away of a year.
     */
    #[OA\Put(path: '/api/v1/payroll/frontier-workers/{employeeId}/nights', operationId: 'saveFrontierNights', description: 'Record the nights a worker did not return home for work in a calendar year.', summary: 'Save frontier nights', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PayrollSaveFrontierYearRequest')), tags: ['Payroll'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollEmployeeId')], responses: [
        new OA\Response(response: 200, description: 'Saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function saveFrontierNights(SaveFrontierYearRequest $request, string $employeeId): PayrollRecordResource
    {
        $this->authorize('create', PayrollRun::class);
        $employee = $this->employee($employeeId);

        $row = PayrollFrontierYear::query()->firstOrNew(['employee_id' => $employee->id, 'year' => (int) $request->validated('year')]);
        $row->nights_away = (int) $request->validated('nights_away');
        $row->save();

        return $this->savedRecord($row);
    }

    /**
     * Data of the exchange of information.
     */
    #[OA\Get(path: '/api/v1/payroll/exports/frontier-exchange', operationId: 'exportFrontierExchange', description: 'CSV with the data of art. 7 of the agreement for the workers it concerns, from the closed runs. The layout belongs to this application and is not an official file.', summary: 'Export frontier exchange data', security: [['bearerAuth' => []]], tags: ['Payroll'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollYearRequired')], responses: [
        new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string'))),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function exportFrontierExchange(PayrollYearExportRequest $request): HttpResponse
    {
        $this->authorize('export', PayrollRun::class);

        return $this->csvResponse($this->frontier->exchangeFile((int) $request->validated('year')));
    }

    /**
     * Audit Swiss work permits for expiry and withholding tax consistency.
     */
    #[OA\Get(path: '/api/v1/payroll/compliance/permit-alerts', operationId: 'listPayrollPermitAlerts', description: 'Audit Swiss work permits (B, C, G, L, Ci, F, N, S, CH, Meldeverfahren) for expiration and withholding tax consistency.', summary: 'Swiss work permit compliance alerts', security: [['bearerAuth' => []]], tags: ['Payroll'], responses: [
        new OA\Response(response: 200, description: 'Permit compliance overview', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
    ])]
    public function permitAlerts(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PayrollRun::class);
        $this->requireCountry('CH', 'Il controllo dei permessi di lavoro svizzeri è disponibile per le aziende svizzere.');

        $asOf = $request->filled('as_of')
            ? CarbonImmutable::parse((string) $request->query('as_of'))
            : CarbonImmutable::today();
        $window = $request->integer('warning_days', SwissPermitComplianceService::DefaultWarningWindowDays);

        return response()->json(['data' => $this->permits->audit($asOf, max(1, min($window, 365)))]);
    }

    /**
     * Export the Swissdec ELM 5.0 / 6.0 salary declaration as XML.
     */
    #[OA\Get(path: '/api/v1/payroll/compliance/salary-declaration/xml', operationId: 'exportSwissdecSalaryDeclarationXml', description: 'Generate the complete 10-domain Swissdec ELM 5.0 / 6.0 salary declaration XML from the closed runs of the year.', summary: 'Export Swissdec ELM XML', security: [['bearerAuth' => []]], tags: ['Payroll'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollYearRequired')], responses: [
        new OA\Response(response: 200, description: 'Swissdec ELM XML file', content: new OA\MediaType(mediaType: 'application/xml', schema: new OA\Schema(type: 'string'))),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function salaryDeclarationXml(PayrollYearExportRequest $request): HttpResponse
    {
        $this->authorize('export', PayrollRun::class);
        $this->requireCountry('CH', 'La dichiarazione dei salari Swissdec ELM è disponibile per le aziende svizzere.');

        $year = (int) $request->validated('year');
        $version = (string) ($request->validated('version') ?? '5.0');
        $declaration = $this->declarations->build($this->tenant(), $year);
        $xml = $this->xmlSerializer->toXml($declaration, $version);

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="Swissdec_ELM_%s_%d.xml"', str_replace('.', '_', $version), $year),
        ]);
    }

    /**
     * Validate a Swissdec ELM 5.0 / 6.0 salary declaration against the bundled XSD schema and domain rules.
     */
    #[OA\Post(path: '/api/v1/payroll/compliance/salary-declaration/validate', operationId: 'validateSwissdecSalaryDeclaration', description: 'Validate the generated or supplied Swissdec ELM XML declaration against the bundled XSD schema and 10-domain arithmetic consistency checks.', summary: 'Validate Swissdec ELM XML', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'year', type: 'integer'), new OA\Property(property: 'xml', type: 'string'), new OA\Property(property: 'version', type: 'string')], type: 'object')), tags: ['Payroll'], responses: [
        new OA\Response(response: 200, description: 'Validation result', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function validateSalaryDeclaration(ValidateSalaryDeclarationRequest $request): JsonResponse
    {
        $this->authorize('export', PayrollRun::class);
        $this->requireCountry('CH', 'La validazione Swissdec ELM è disponibile per le aziende svizzere.');

        $version = (string) ($request->validated('version') ?? '5.0');
        $xml = $request->validated('xml');

        if ($xml === null || trim((string) $xml) === '') {
            $year = (int) $request->validated('year');
            $declaration = $this->declarations->build($this->tenant(), $year);
            $xml = $this->xmlSerializer->toXml($declaration, $version);
        }

        return response()->json(['data' => $this->xsdValidator->validate((string) $xml)]);
    }

    private function csvResponse(GeneratedFile $file): HttpResponse
    {
        return response($file->content, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.Str::ascii($file->fileName).'"',
            'X-Filing-Summary' => rawurlencode($file->summary),
        ]);
    }
}
