<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Controllers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Modules\Payroll\Exports\GeneratedFile;
use Modules\Payroll\Exports\PayrollFilingService;
use Modules\Payroll\Http\Requests\PayrollMonthExportRequest;
use Modules\Payroll\Http\Requests\PayrollYearExportRequest;
use Modules\Payroll\Models\PayrollRun;
use OpenApi\Attributes as OA;

/**
 * CSV data for the software of the funds and of the tax agency: no telematic file is generated here.
 */
final class PayrollExportController extends PayrollController
{
    public function __construct(TenantContext $tenantContext, private readonly PayrollFilingService $filings)
    {
        parent::__construct($tenantContext);
    }

    /**
     * Swiss annual salary declaration.
     */
    #[OA\Get(
        path: '/api/v1/payroll/exports/salary-declaration',
        operationId: 'exportSwissSalaryDeclaration',
        description: 'Swiss companies: annual salary declaration in CSV from the closed runs (AHV, ALV, UVG, KTG, BVG, withholding tax). Swissdec ELM is not implemented: the file goes through the portals of the funds. The X-Filing-Summary header holds a URL-encoded note.',
        summary: 'Export salary declaration',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollYearRequired')],
        responses: [
            new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string'))),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function salaryDeclaration(PayrollYearExportRequest $request): HttpResponse
    {
        $this->authorize('export', PayrollRun::class);
        $this->requireCountry('CH', 'La dichiarazione annuale dei salari è disponibile per le aziende svizzere.');

        return $this->csvResponse($this->filings->salaryDeclaration((int) $request->validated('year')));
    }

    /**
     * Italian payroll F24.
     */
    #[OA\Get(
        path: '/api/v1/payroll/exports/f24',
        operationId: 'exportPayrollF24',
        description: 'Italian companies: F24 rows of a month in CSV (1001, 3802, 3848, INPS DM10). The X-Filing-Summary header holds the URL-encoded due date and totals.',
        summary: 'Export payroll F24',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/PayrollYearRequired'),
            new OA\Parameter(ref: '#/components/parameters/PayrollMonthRequired'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string'))),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function f24(PayrollMonthExportRequest $request): HttpResponse
    {
        $this->authorize('export', PayrollRun::class);
        $this->requireItalian();

        return $this->csvResponse($this->filings->payrollF24((int) $request->validated('year'), (int) $request->validated('month')));
    }

    /**
     * UniEmens data of a month.
     */
    #[OA\Get(
        path: '/api/v1/payroll/exports/uniemens',
        operationId: 'exportUniEmensData',
        description: 'Italian companies: monthly data for the UniEmens declarations in CSV, to be loaded into the sending software. No telematic file is generated.',
        summary: 'Export UniEmens data',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/PayrollYearRequired'),
            new OA\Parameter(ref: '#/components/parameters/PayrollMonthRequired'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string'))),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function uniEmens(PayrollMonthExportRequest $request): HttpResponse
    {
        $this->authorize('export', PayrollRun::class);
        $this->requireItalian();

        return $this->csvResponse($this->filings->uniEmensData((int) $request->validated('year'), (int) $request->validated('month')));
    }

    /**
     * Certificazione Unica data of a year.
     */
    #[OA\Get(
        path: '/api/v1/payroll/exports/cu',
        operationId: 'exportCertificazioneUnicaData',
        description: 'Italian companies: annual data for the Certificazione Unica in CSV, to be carried into the official software. No telematic file is generated.',
        summary: 'Export CU data',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollYearRequired')],
        responses: [
            new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string'))),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function certificazioneUnica(PayrollYearExportRequest $request): HttpResponse
    {
        $this->authorize('export', PayrollRun::class);
        $this->requireItalian();

        return $this->csvResponse($this->filings->cuData((int) $request->validated('year')));
    }

    private function requireItalian(): void
    {
        $this->requireCountry('IT', 'Gli adempimenti italiani sono disponibili per le aziende italiane.');
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
