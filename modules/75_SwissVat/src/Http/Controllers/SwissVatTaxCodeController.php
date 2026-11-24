<?php

declare(strict_types=1);

namespace Modules\SwissVat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\SwissVat\Http\Controllers\Concerns\ResolvesSwissVatContext;
use Modules\SwissVat\Http\Requests\StoreSwissVatTaxCodeRequest;
use Modules\SwissVat\Http\Requests\UpdateSwissVatTaxCodeRequest;
use Modules\SwissVat\Http\Resources\SwissVatTaxCodeResource;
use Modules\SwissVat\Services\SwissVatConfigService;
use Modules\SwissVat\Services\SwissVatRateCatalogService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Swiss VAT tax codes (8.1%, 2.6%, 3.8%, 0% exempt with/without credit, Bezugsteuer) and Saldosteuersatz sector rates.
 */
final class SwissVatTaxCodeController extends Controller
{
    use ApiResponse;
    use ResolvesSwissVatContext;

    public function __construct(
        private readonly SwissVatConfigService $configService,
        private readonly SwissVatRateCatalogService $catalogService,
    ) {}

    #[OA\Get(path: '/api/v1/swiss-vat/tax-codes', operationId: 'listSwissVatTaxCodes', summary: 'List Swiss VAT tax codes', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], responses: [new OA\Response(response: 200, description: 'Global and tenant Swiss VAT tax codes')])]
    public function index(): JsonResponse
    {
        return $this->successResponse(SwissVatTaxCodeResource::collection($this->configService->listTaxCodes($this->tenantId())));
    }

    #[OA\Post(path: '/api/v1/swiss-vat/tax-codes', operationId: 'createSwissVatTaxCode', summary: 'Create custom Swiss VAT tax code', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], responses: [new OA\Response(response: 201, description: 'Tax code created')])]
    public function store(StoreSwissVatTaxCodeRequest $request): JsonResponse
    {
        $code = $this->configService->createTaxCode($this->tenantId(), $this->userId($request), $request->validated());

        return $this->successResponse(new SwissVatTaxCodeResource($code), __('swiss_vat.messages.tax_code_created'), Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/swiss-vat/tax-codes/{id}', operationId: 'updateSwissVatTaxCode', summary: 'Update custom Swiss VAT tax code', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Tax code updated')])]
    public function update(UpdateSwissVatTaxCodeRequest $request, string $id): JsonResponse
    {
        $code = $this->configService->updateTaxCode($this->tenantId(), $id, $this->userId($request), $request->validated());

        return $this->successResponse(new SwissVatTaxCodeResource($code), __('swiss_vat.messages.tax_code_updated'));
    }

    #[OA\Get(path: '/api/v1/swiss-vat/sss-sectors', operationId: 'listSwissVatSssSectors', summary: 'List official ESTV Saldosteuersatz sector rates', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], responses: [new OA\Response(response: 200, description: 'Official Saldosteuersatz sector bands')])]
    public function sssSectors(): JsonResponse
    {
        $sectors = array_map(
            static fn (array $s): array => [
                'sector_code' => $s['sector_code'],
                'rate_percent' => $s['rate_percent'],
                'label_key' => $s['label_key'],
                'label' => __($s['label_key']),
                'afc_box' => $s['afc_box'],
            ],
            $this->catalogService->saldosteuersatzSectors(),
        );

        return $this->successResponse($sectors);
    }
}
