<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\SwissQrBill\SwissQrBillGeneratorInterface;
use App\Contracts\SwissQrBill\SwissQrBillParserInterface;
use App\Exceptions\QrBillGenerationException;
use App\Exceptions\QrBillParseException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SwissQrBill\GenerateQrBillPdfRequest;
use App\Http\Requests\SwissQrBill\ParseQrBillRequest;
use App\Http\Requests\SwissQrBill\ValidateQrReferenceRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Services\SwissQrBill\QrBillGeneratorService;
use App\Services\SwissQrBill\QrBillPdfOptions;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class SwissQrBillController extends Controller
{
    use ApiResponse, AuthorizesRequests;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Post(
        path: '/api/v1/swiss-qr-bill/generate-pdf',
        operationId: 'generateSwissQrBillPdf',
        description: 'Render the SIX payment slip (receipt 62 mm + payment part 148 mm, QR code 46x46 mm) of an invoice as PDF, attached to an A4 page or as a standalone 210x105 mm slip.',
        summary: 'Generate Swiss QR-bill PDF',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['invoice_id'],
            properties: [
                new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'mode', type: 'string', enum: ['A4_ATTACHED', 'STANDALONE']),
                new OA\Property(property: 'perforation', type: 'string', enum: ['NONE', 'DASHED_LINE', 'DASHED_LINE_WITH_SCISSORS']),
                new OA\Property(property: 'language', type: 'string', enum: ['it', 'de', 'fr', 'en']),
            ]
        )),
        tags: ['Swiss QR'],
        responses: [
            new OA\Response(response: 200, description: 'PDF document', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 422, description: 'Validation or QR generation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function generatePdf(GenerateQrBillPdfRequest $request, SwissQrBillGeneratorInterface $generator): HttpResponse|JsonResponse
    {
        $invoice = Invoice::findOrFail($request->validated('invoice_id'));
        $this->authorize('generateQrBill', $invoice);
        $tenant = $this->tenantContext->getTenant();

        $options = new QrBillPdfOptions(
            mode: $request->validated('mode') ?? QrBillPdfOptions::MODE_A4_ATTACHED,
            perforation: $request->validated('perforation') ?? ($tenant?->qr_bill_perforation ?: QrBillPdfOptions::PERFORATION_DASHED_LINE_WITH_SCISSORS),
            language: $request->validated('language') ?? app()->getLocale(),
        );

        try {
            $pdf = $generator->generatePdf($invoice, $options);
        } catch (QrBillGenerationException $e) {
            return $this->problemDetailsResponse(
                title: __('qr_bill.problem_title'),
                detail: $e->getMessage(),
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                invalidParams: ['violations' => $e->violations]
            );
        }

        return new HttpResponse($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="qr-bill-'.preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $invoice->invoice_number).'.pdf"',
        ]);
    }

    #[OA\Post(
        path: '/api/v1/swiss-qr-bill/parse',
        operationId: 'parseSwissQrBill',
        description: 'Decode a Swiss QR-bill from an uploaded PDF or image (PNG, JPG, WebP) or from a raw SPC payload into a structured draft for a supplier invoice. Nothing is stored.',
        summary: 'Parse Swiss QR-bill',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(properties: [
                new OA\Property(property: 'file', type: 'string', format: 'binary'),
                new OA\Property(property: 'payload', type: 'string'),
            ])
        )),
        tags: ['Swiss QR'],
        responses: [
            new OA\Response(response: 200, description: 'Parsed QR-bill', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'No readable Swiss QR-bill', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function parse(ParseQrBillRequest $request, SwissQrBillParserInterface $parser): JsonResponse
    {
        try {
            $parsed = $request->hasFile('file')
                ? $parser->parseFile((string) file_get_contents($request->file('file')->getRealPath()))
                : $parser->parsePayload((string) $request->validated('payload'));
        } catch (QrBillParseException $e) {
            return $this->problemDetailsResponse(
                title: __('qr_bill.problem_title'),
                detail: $e->getMessage(),
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                invalidParams: ['file' => $e->reason]
            );
        }

        return $this->successResponse($parsed->toArray(), __('qr_bill.messages.parsed'));
    }

    #[OA\Post(
        path: '/api/v1/swiss-qr-bill/validate-reference',
        operationId: 'validateSwissQrBillReference',
        description: 'Check an IBAN/QR-IBAN and payment reference pair: a QR-IBAN needs a 27-digit QRR (modulo 10 recursive), a standard IBAN takes an ISO 11649 SCOR (modulo 97-10) or no reference.',
        summary: 'Validate QR-bill reference',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['iban'],
            properties: [
                new OA\Property(property: 'iban', type: 'string'),
                new OA\Property(property: 'reference', type: 'string', nullable: true),
            ]
        )),
        tags: ['Swiss QR'],
        responses: [
            new OA\Response(response: 200, description: 'Validation result', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function validateReference(ValidateQrReferenceRequest $request, QrBillGeneratorService $validator): JsonResponse
    {
        $iban = (string) $request->validated('iban');
        $result = $validator->checkReference($iban, $request->validated('reference'));

        return $this->successResponse([
            'valid' => $result['valid'],
            'reference_type' => $result['type'],
            'reference' => $result['normalized'],
            'formatted_reference' => $result['type'] === 'QRR' && $result['normalized'] !== null ? $validator->formatScorReference($result['normalized']) : $result['normalized'],
            'is_qr_iban' => $validator->isValidQrIban($iban),
            'errors' => array_map(fn (string $reason): string => __("qr_bill.errors.{$reason}"), $result['errors']),
            'error_codes' => $result['errors'],
        ], __('qr_bill.messages.reference_checked'));
    }
}
