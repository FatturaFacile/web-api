<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Modules\ItalianVat\Http\Controllers\Concerns\ResolvesItalianVatContext;
use Modules\ItalianVat\Http\Requests\EmptyActionRequest;
use Modules\ItalianVat\Http\Requests\F24PaymentRequest;
use Modules\ItalianVat\Http\Resources\CuCertificationResource;
use Modules\ItalianVat\Http\Resources\F24PaymentResource;
use Modules\ItalianVat\Services\CuCertificationService;
use Modules\ItalianVat\Services\CuTelematicFileBuilder;
use Modules\ItalianVat\Services\F24PaymentService;
use OpenApi\Attributes as OA;

/**
 * The Certificazione Unica of the self-employed and the agents, and the F24 payments of their withholdings.
 */
final class CuController extends Controller
{
    use ApiResponse;
    use ResolvesItalianVatContext;

    public function __construct(
        private readonly CuCertificationService $certifications,
        private readonly CuTelematicFileBuilder $fileBuilder,
        private readonly F24PaymentService $f24,
    ) {}

    #[OA\Get(path: '/api/v1/italian-vat/cu/{year}', operationId: 'listItalianVatCu', summary: 'The certifications of a year', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'The certifications')])]
    public function index(string $year): JsonResponse
    {
        return $this->successResponse(CuCertificationResource::collection($this->certifications->list($this->tenantId(), (int) $year)));
    }

    #[OA\Post(path: '/api/v1/italian-vat/cu/{year}/build', operationId: 'buildItalianVatCu', summary: 'Build the certifications of a year', description: 'From the withholding register: gross amount, taxable amount, withheld tax and contributions of each recipient and reason, by date of payment. Issued certifications are not touched.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'The certifications with their validation errors')])]
    public function build(EmptyActionRequest $request, string $year): JsonResponse
    {
        return $this->successResponse(CuCertificationResource::collection($this->certifications->build($this->tenantId(), (int) $year)), 'Certifications built');
    }

    #[OA\Post(path: '/api/v1/italian-vat/cu/{year}/issue', operationId: 'issueItalianVatCu', summary: 'Issue the certifications of a year', description: 'Only when every certification validates.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'The issued certifications'), new OA\Response(response: 422, description: 'Some certifications do not validate')])]
    public function issue(EmptyActionRequest $request, string $year): JsonResponse
    {
        return $this->successResponse(CuCertificationResource::collection($this->certifications->issue($this->tenantId(), (int) $year)), 'Certifications issued');
    }

    #[OA\Get(path: '/api/v1/italian-vat/cu/{year}/validation', operationId: 'validateItalianVatCuExport', summary: 'Pre-export validation of the CU file', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'The errors that stop the export, empty when the file can be produced')])]
    public function validation(string $year): JsonResponse
    {
        $tenantId = $this->tenantId();
        $errors = $this->fileBuilder->preflight(Tenant::query()->findOrFail($tenantId), $this->certifications->list($tenantId, (int) $year));

        return $this->successResponse(['valid' => $errors === [], 'errors' => $errors]);
    }

    #[OA\Get(path: '/api/v1/italian-vat/cu/{year}/file', operationId: 'downloadItalianVatCuFile', summary: 'The telematic file of the CU', description: 'Fixed-length records of 1900 characters. The file is checked before it is produced and must still pass the control software of the Agenzia delle Entrate.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'The telematic file', content: new OA\MediaType(mediaType: 'text/plain')), new OA\Response(response: 422, description: 'The export does not validate')])]
    public function file(string $year): HttpResponse
    {
        $tenantId = $this->tenantId();
        $file = $this->fileBuilder->build(Tenant::query()->findOrFail($tenantId), (int) $year, $this->certifications->list($tenantId, (int) $year)->all());

        return $this->download($file['content'], $file['file_name'], 'text/plain; charset=US-ASCII');
    }

    #[OA\Get(path: '/api/v1/italian-vat/cu/certifications/{id}/pdf', operationId: 'downloadItalianVatCuPdf', summary: 'The CU of a recipient as PDF', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The PDF', content: new OA\MediaType(mediaType: 'application/pdf')), new OA\Response(response: 404, description: 'Unknown certification')])]
    public function pdf(string $id): HttpResponse
    {
        $file = $this->certifications->pdf($this->tenantId(), $id);

        return $this->download($file['content'], $file['file_name'], 'application/pdf');
    }

    #[OA\Get(path: '/api/v1/italian-vat/f24/{year}', operationId: 'listItalianVatF24', summary: 'F24 payments of the withholdings of a year', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'The F24 due and paid, by month and tax code')])]
    public function f24(string $year): JsonResponse
    {
        return $this->successResponse(F24PaymentResource::collection($this->f24->list($this->tenantId(), (int) $year)));
    }

    #[OA\Post(path: '/api/v1/italian-vat/f24/{year}/sync', operationId: 'syncItalianVatF24', summary: 'Align the F24 due with the withholding settlements', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'The F24 payments')])]
    public function f24Sync(EmptyActionRequest $request, string $year): JsonResponse
    {
        return $this->successResponse(F24PaymentResource::collection($this->f24->sync($this->tenantId(), (int) $year)), 'F24 synchronised');
    }

    #[OA\Get(path: '/api/v1/italian-vat/f24/{year}/reconciliation', operationId: 'reconcileItalianVatF24', summary: 'Reconcile the F24 payments with the withholdings certified in the CU', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Certified, due and paid amounts with the differences')])]
    public function f24Reconciliation(string $year): JsonResponse
    {
        return $this->successResponse($this->f24->reconcile($this->tenantId(), (int) $year));
    }

    #[OA\Post(path: '/api/v1/italian-vat/f24/payments/{id}/payments', operationId: 'payItalianVatF24', summary: 'Record a payment made with the F24', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The F24 payment'), new OA\Response(response: 404, description: 'Unknown F24 payment')])]
    public function f24Pay(F24PaymentRequest $request, string $id): JsonResponse
    {
        $payment = $this->f24->recordPayment($this->tenantId(), $id, (string) $request->validated('amount'), (string) $request->validated('paid_at'), $request->validated('reference'));

        return $this->successResponse(new F24PaymentResource($payment), 'Payment recorded');
    }
}
