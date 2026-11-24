<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\ItalianVat\Http\Controllers\Concerns\ResolvesItalianVatContext;
use Modules\ItalianVat\Http\Requests\GoodsMovementRequest;
use Modules\ItalianVat\Http\Requests\GoodsQueryRequest;
use Modules\ItalianVat\Http\Resources\ThirdPartyGoodsMovementResource;
use Modules\ItalianVat\Services\ThirdPartyGoodsService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The register of the goods held on account of third parties.
 */
final class ThirdPartyGoodsController extends Controller
{
    use ApiResponse;
    use ResolvesItalianVatContext;

    public function __construct(private readonly ThirdPartyGoodsService $goods) {}

    #[OA\Get(path: '/api/v1/italian-vat/goods-movements', operationId: 'listItalianVatGoodsMovements', summary: 'Movements of the goods on account of third parties', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], responses: [new OA\Response(response: 200, description: 'The movements in protocol order')])]
    public function index(GoodsQueryRequest $request): JsonResponse
    {
        $from = $request->validated('from');
        $to = $request->validated('to');
        $movements = $this->goods->movements(
            $this->tenantId(),
            $request->validated('contact_id'),
            $from === null ? CarbonImmutable::create(2000, 1, 1) : CarbonImmutable::parse((string) $from),
            $to === null ? CarbonImmutable::create(2100, 12, 31) : CarbonImmutable::parse((string) $to),
        );

        return $this->successResponse(ThirdPartyGoodsMovementResource::collection(collect($movements)));
    }

    #[OA\Post(path: '/api/v1/italian-vat/goods-movements', operationId: 'createItalianVatGoodsMovement', summary: 'Record a movement of goods on account of a third party', description: 'Goods cannot leave the custody beyond what was received. The movement takes the next progressive protocol number.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], responses: [new OA\Response(response: 201, description: 'The movement'), new OA\Response(response: 409, description: 'The period is locked'), new OA\Response(response: 422, description: 'Insufficient balance or validation error')])]
    public function store(GoodsMovementRequest $request): JsonResponse
    {
        $movement = $this->goods->record($this->tenantId(), $this->userId($request), $request->validated());

        return $this->successResponse(new ThirdPartyGoodsMovementResource($movement), 'Movement registered', Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/italian-vat/goods-balances', operationId: 'listItalianVatGoodsBalances', summary: 'Goods still held for each third party', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], responses: [new OA\Response(response: 200, description: 'Quantity and value held, per third party and description')])]
    public function balances(GoodsQueryRequest $request): JsonResponse
    {
        $asOf = $request->validated('as_of');

        return $this->successResponse($this->goods->balances($this->tenantId(), $request->validated('contact_id'), $asOf === null ? null : CarbonImmutable::parse((string) $asOf)));
    }
}
