<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Modules\SwissFiscal\Domain\Enums\SwissVatRate;
use Modules\SwissFiscal\Domain\Exceptions\SwissFiscalException;
use Modules\SwissFiscal\Domain\ValueObjects\FiscalDocument;
use Modules\SwissFiscal\Infrastructure\FiscalDocumentGeneratorRegistry;
use Modules\SwissFiscal\Infrastructure\Iso20022\Camt054Parser;
use Modules\SwissFiscal\Infrastructure\QrBill\SwissQrBillGenerator;
use Modules\SwissFiscal\Presentation\Http\Requests\GenerateFiscalDocumentRequest;
use Modules\SwissFiscal\Presentation\Http\Requests\GenerateQrBillRequest;
use Modules\SwissFiscal\Presentation\Http\Requests\ParseCamt054Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class SwissFiscalController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(path: '/api/v1/swiss-fiscal/vat-rates', operationId: 'listSwissVatRates', summary: 'Swiss VAT rates (standard 8.1%, reduced 2.6%, accommodation 3.8%)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss Fiscal'], responses: [new OA\Response(response: 200, description: 'The rates')])]
    public function vatRates(): JsonResponse
    {
        return $this->successResponse(array_map(
            static fn (SwissVatRate $rate): array => ['code' => $rate->value, 'percent' => $rate->percent()],
            SwissVatRate::cases(),
        ));
    }

    #[OA\Post(path: '/api/v1/swiss-fiscal/qr-bills', operationId: 'generateSwissQrBill', description: 'Validates the QR-bill (QR-IBAN with QR reference, or IBAN with SCOR/NON; CHF or EUR) and returns the PDF payment slip, or the payload and QR SVG with format=json.', summary: 'Generate a Swiss QR-bill', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss Fiscal'], responses: [new OA\Response(response: 200, description: 'The payment slip'), new OA\Response(response: 422, description: 'The QR-bill violates the SIX rules')])]
    public function qrBill(GenerateQrBillRequest $request, SwissQrBillGenerator $generator): JsonResponse|HttpResponse
    {
        return $this->guarded(function () use ($request, $generator): JsonResponse|HttpResponse {
            $document = $generator->generate($this->tenantId(), $request->validated());

            if ($request->input('format') === 'json') {
                return $this->successResponse($document->metadata);
            }

            return $this->download($document);
        });
    }

    #[OA\Post(path: '/api/v1/swiss-fiscal/fiscal-documents', operationId: 'generateFiscalDocument', description: 'Runs the country strategy (IT: schema-validated FatturaPA XML of an invoice).', summary: 'Generate a country fiscal document', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss Fiscal'], responses: [new OA\Response(response: 200, description: 'The document'), new OA\Response(response: 422, description: 'The document is not valid')])]
    public function fiscalDocument(GenerateFiscalDocumentRequest $request, FiscalDocumentGeneratorRegistry $registry): JsonResponse|HttpResponse
    {
        return $this->guarded(fn (): HttpResponse => $this->download(
            $registry->for((string) $request->input('country'))->generate($this->tenantId(), $request->validated()),
        ));
    }

    #[OA\Post(path: '/api/v1/swiss-fiscal/camt054', operationId: 'parseCamt054', description: 'Parses an ISO 20022 camt.054 notification into reconciliation entries carrying the QR/SCOR reference and whether its checksum is valid.', summary: 'Parse a camt.054 notification', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss Fiscal'], responses: [new OA\Response(response: 200, description: 'The entries'), new OA\Response(response: 422, description: 'Not a camt.054 document')])]
    public function camt054(ParseCamt054Request $request, Camt054Parser $parser): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse($parser->parse((string) $request->input('xml'))));
    }

    private function download(FiscalDocument $document): HttpResponse
    {
        return response($document->content, Response::HTTP_OK, [
            'Content-Type' => $document->mimeType,
            'Content-Disposition' => 'attachment; filename="'.$document->filename.'"',
        ]);
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }

    /**
     * Maps module rule violations to problem details.
     *
     * @template T
     *
     * @param  callable(): T  $action
     * @return T|JsonResponse
     */
    private function guarded(callable $action): mixed
    {
        try {
            return $action();
        } catch (SwissFiscalException $exception) {
            return $this->problemDetailsResponse('Fiscal Document Rule Violation', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
