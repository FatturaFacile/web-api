<?php

declare(strict_types=1);

namespace Modules\SwissVat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\SwissVat\Application\Commands\UpsertSwissVatConfigCommand;
use Modules\SwissVat\Application\Commands\UpsertSwissVatConfigHandler;
use Modules\SwissVat\Http\Controllers\Concerns\ResolvesSwissVatContext;
use Modules\SwissVat\Http\Requests\UpsertSwissVatConfigRequest;
use Modules\SwissVat\Http\Resources\SwissVatConfigResource;
use Modules\SwissVat\Services\SwissVatConfigService;
use OpenApi\Attributes as OA;

/**
 * Tenant Swiss VAT configuration (Effective vs Saldosteuersatz, consideration basis, frequency, UID-MWST).
 */
final class SwissVatConfigController extends Controller
{
    use ApiResponse;
    use ResolvesSwissVatContext;

    public function __construct(
        private readonly SwissVatConfigService $configService,
        private readonly UpsertSwissVatConfigHandler $upsertHandler,
    ) {}

    #[OA\Get(path: '/api/v1/swiss-vat/config', operationId: 'getSwissVatConfig', summary: 'Get Swiss VAT configuration', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], responses: [new OA\Response(response: 200, description: 'Swiss VAT tenant configuration')])]
    public function show(): JsonResponse
    {
        return $this->successResponse(new SwissVatConfigResource($this->configService->getOrDefault($this->tenantId())));
    }

    #[OA\Put(path: '/api/v1/swiss-vat/config', operationId: 'upsertSwissVatConfig', summary: 'Update Swiss VAT configuration', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], responses: [new OA\Response(response: 200, description: 'Configuration saved'), new OA\Response(response: 422, description: 'Validation error')])]
    public function update(UpsertSwissVatConfigRequest $request): JsonResponse
    {
        $config = $this->upsertHandler->handle(new UpsertSwissVatConfigCommand(
            tenantId: $this->tenantId(),
            actorUserId: $this->userId($request),
            attributes: $request->validated(),
        ));

        return $this->successResponse(new SwissVatConfigResource($config), __('swiss_vat.messages.config_saved'));
    }
}
