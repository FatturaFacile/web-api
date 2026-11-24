<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\ItalianVat\Http\Controllers\Concerns\ResolvesItalianVatContext;
use Modules\ItalianVat\Http\Requests\ReceiptsByRateRequest;
use Modules\ItalianVat\Http\Requests\VentilationRequest;
use Modules\ItalianVat\Http\Resources\VatRegisterEntryResource;
use Modules\ItalianVat\Http\Resources\VatVentilationResource;
use Modules\ItalianVat\Services\ReceiptsRegisterService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The register of the daily receipts (corrispettivi) and the ventilation of the takings.
 */
final class ReceiptsController extends Controller
{
    use ApiResponse;
    use ResolvesItalianVatContext;

    public function __construct(private readonly ReceiptsRegisterService $receipts) {}

    #[OA\Post(path: '/api/v1/italian-vat/receipts/ventilation/preview', operationId: 'previewItalianVatVentilation', summary: 'Preview the ventilation of the takings', description: 'Splits the gross takings in proportion to the purchases at each rate and scorporates the VAT. Nothing is saved.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], responses: [new OA\Response(response: 200, description: 'The split by rate'), new OA\Response(response: 422, description: 'Validation error')])]
    public function preview(VentilationRequest $request): JsonResponse
    {
        return $this->successResponse($this->receipts->ventilate((string) $request->validated('gross_takings'), $request->validated('purchase_basis')));
    }

    #[OA\Post(path: '/api/v1/italian-vat/receipts/ventilation', operationId: 'registerItalianVatVentilation', summary: 'Register the ventilated takings of a day', description: 'One line of the daily receipts register for each rate, with the progressive protocol number.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], responses: [new OA\Response(response: 201, description: 'The ventilation and its register lines'), new OA\Response(response: 409, description: 'The period is locked')])]
    public function ventilate(VentilationRequest $request): JsonResponse
    {
        $result = $this->receipts->registerVentilated($this->tenantId(), $this->userId($request), $request->validated());

        return $this->successResponse([
            'ventilation' => new VatVentilationResource($result['ventilation']),
            'entries' => VatRegisterEntryResource::collection(collect($result['entries'])),
        ], 'Takings registered', Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/italian-vat/receipts/by-rate', operationId: 'registerItalianVatReceiptsByRate', summary: 'Register takings already split by rate', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], responses: [new OA\Response(response: 201, description: 'The register lines'), new OA\Response(response: 409, description: 'The period is locked')])]
    public function byRate(ReceiptsByRateRequest $request): JsonResponse
    {
        $entries = $this->receipts->registerByRate($this->tenantId(), $this->userId($request), $request->validated());

        return $this->successResponse(VatRegisterEntryResource::collection(collect($entries)), 'Takings registered', Response::HTTP_CREATED);
    }
}
