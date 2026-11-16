<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Controllers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Modules\Payroll\Http\Requests\GenerateSalaryCertificatesRequest;
use Modules\Payroll\Http\Requests\ListEmployeeDocumentsRequest;
use Modules\Payroll\Http\Resources\PayrollSalaryCertificateResource;
use Modules\Payroll\Models\SalaryCertificate;
use Modules\Payroll\Policies\EmployeeDocumentPolicy;
use Modules\Payroll\Services\PayrollDocumentService;
use Modules\Payroll\Services\SalaryCertificateService;
use OpenApi\Attributes as OA;

/**
 * Annual Swiss salary certificates (not the official form with the barcode).
 */
final class SalaryCertificateController extends PayrollController
{
    public function __construct(
        TenantContext $tenantContext,
        private readonly SalaryCertificateService $certificates,
        private readonly PayrollDocumentService $documents,
        private readonly EmployeeDocumentPolicy $access,
    ) {
        parent::__construct($tenantContext);
    }

    /**
     * List salary certificates.
     */
    #[OA\Get(
        path: '/api/v1/payroll/salary-certificates',
        operationId: 'listSalaryCertificates',
        description: 'Return the salary certificates of the company, or only the own ones for an employee without payroll access.',
        summary: 'List salary certificates',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/PayrollEmployeeFilter'),
            new OA\Parameter(ref: '#/components/parameters/PayrollYearFilter'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Salary certificates', content: new OA\JsonContent(ref: '#/components/schemas/PayrollSalaryCertificatePage')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function index(ListEmployeeDocumentsRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SalaryCertificate::class);

        $employeeId = $request->validated('employee_id');

        if ($employeeId !== null) {
            $this->authorize('viewEmployee', [SalaryCertificate::class, (string) $employeeId]);
        }

        $actor = $this->actor($request);
        $certificates = SalaryCertificate::query()
            ->when($employeeId !== null, static fn ($query) => $query->where('employee_id', $employeeId))
            ->when(! $this->access->viewAllEmployees($actor), fn ($query) => $query->whereIn('employee_id', $this->access->ownEmployeeIds($actor)))
            ->when($request->validated('year') !== null, static fn ($query) => $query->where('year', (int) $request->validated('year')))
            ->orderByDesc('year')
            ->gridPaginate((int) $request->validated('per_page', 50));

        return PayrollSalaryCertificateResource::collection($certificates);
    }

    /**
     * Generate the certificates of a year from the closed runs.
     */
    #[OA\Post(
        path: '/api/v1/payroll/salary-certificates/generate',
        operationId: 'generateSalaryCertificates',
        description: 'Swiss companies: generate the certificates of a year from the closed runs. Generating again replaces those of the year.',
        summary: 'Generate salary certificates',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['year'], properties: [new OA\Property(property: 'year', type: 'integer', example: 2026)])),
        tags: ['Payroll'],
        responses: [
            new OA\Response(response: 200, description: 'Number of certificates generated', content: new OA\JsonContent(ref: '#/components/schemas/PayrollGeneratedCertificates')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ]
    )]
    public function generate(GenerateSalaryCertificatesRequest $request): JsonResponse
    {
        $this->authorize('create', SalaryCertificate::class);
        $this->requireCountry('CH', 'Il certificato di salario è disponibile per le aziende svizzere.');

        return response()->json(['data' => ['generated' => $this->certificates->generate((int) $request->validated('year'))]]);
    }

    /**
     * Show a certificate.
     */
    #[OA\Get(
        path: '/api/v1/payroll/salary-certificates/{id}',
        operationId: 'showSalaryCertificate',
        description: 'Return a salary certificate.',
        summary: 'Show salary certificate',
        security: [['bearerAuth' => []]],
        tags: ['Payroll'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Salary certificate', content: new OA\JsonContent(ref: '#/components/schemas/PayrollSalaryCertificateEnvelope')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        ]
    )]
    public function show(string $id): PayrollSalaryCertificateResource
    {
        $certificate = SalaryCertificate::query()->findOrFail($id);
        $this->authorize('view', $certificate);

        return new PayrollSalaryCertificateResource($certificate);
    }

    /**
     * Print a certificate.
     */
    #[OA\Get(
        path: '/api/v1/payroll/salary-certificates/{id}/pdf',
        operationId: 'downloadSalaryCertificatePdf',
        description: 'Return the salary certificate as a PDF document, with the boxes of form 11. It is not the official form with the barcode.',
        summary: 'Download salary certificate',
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
        $certificate = SalaryCertificate::query()->findOrFail($id);
        $this->authorize('view', $certificate);

        return $this->pdfResponse(
            $this->documents->salaryCertificate($certificate, $this->tenant()),
            sprintf('Certificato_salario_%d_%s.pdf', $certificate->year, str_replace(' ', '_', (string) $certificate->employee?->full_name)),
        );
    }
}
