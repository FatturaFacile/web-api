<?php

declare(strict_types=1);

namespace Modules\SwissVat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SwissVat\Http\Controllers\Concerns\ResolvesSwissVatContext;
use Modules\SwissVat\Http\Requests\DetectBezugsteuerRequest;
use Modules\SwissVat\Http\Requests\EmptySwissVatActionRequest;
use Modules\SwissVat\Http\Requests\StoreBezugsteuerRequest;
use Modules\SwissVat\Http\Resources\BezugsteuerEntryResource;
use Modules\SwissVat\Services\BezugsteuerDetectionService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Swiss Bezugsteuer (acquisition tax on foreign services, Art. 45 LIVA -> Box 382/383).
 */
final class BezugsteuerController extends Controller
{
    use ApiResponse;
    use ResolvesSwissVatContext;

    public function __construct(private readonly BezugsteuerDetectionService $bezugsteuer) {}

    #[OA\Get(path: '/api/v1/swiss-vat/bezugsteuer', operationId: 'listSwissVatBezugsteuer', summary: 'List Bezugsteuer entries', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], responses: [new OA\Response(response: 200, description: 'Bezugsteuer entries')])]
    public function index(Request $request): JsonResponse
    {
        $entries = $this->bezugsteuer->list(
            tenantId: $this->tenantId(),
            status: $request->query('status') ? (string) $request->query('status') : null,
            from: $request->query('period_from') ? (string) $request->query('period_from') : null,
            to: $request->query('period_to') ? (string) $request->query('period_to') : null,
        );

        return $this->successResponse(BezugsteuerEntryResource::collection($entries));
    }

    #[OA\Post(path: '/api/v1/swiss-vat/bezugsteuer', operationId: 'createSwissVatBezugsteuer', summary: 'Record a manual Bezugsteuer entry', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], responses: [new OA\Response(response: 201, description: 'Entry created')])]
    public function store(StoreBezugsteuerRequest $request): JsonResponse
    {
        $entry = $this->bezugsteuer->createManual($this->tenantId(), $this->userId($request), $request->validated());

        return $this->successResponse(new BezugsteuerEntryResource($entry), __('swiss_vat.messages.bezugsteuer_created'), Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/swiss-vat/bezugsteuer/detect', operationId: 'detectSwissVatBezugsteuer', summary: 'Auto-detect cross-border foreign service bills subject to Bezugsteuer', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], responses: [new OA\Response(response: 200, description: 'Newly detected entries')])]
    public function detect(DetectBezugsteuerRequest $request): JsonResponse
    {
        $detected = $this->bezugsteuer->detectForPeriod(
            tenantId: $this->tenantId(),
            from: CarbonImmutable::parse((string) $request->validated('period_from')),
            to: CarbonImmutable::parse((string) $request->validated('period_to')),
            userId: $this->userId($request),
        );

        return $this->successResponse(BezugsteuerEntryResource::collection($detected), __('swiss_vat.messages.bezugsteuer_detected'));
    }

    #[OA\Post(path: '/api/v1/swiss-vat/bezugsteuer/{id}/confirm', operationId: 'confirmSwissVatBezugsteuer', summary: 'Confirm a detected Bezugsteuer entry', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Entry confirmed')])]
    public function confirm(EmptySwissVatActionRequest $request, string $id): JsonResponse
    {
        $entry = $this->bezugsteuer->confirm($this->tenantId(), $id, $this->userId($request));

        return $this->successResponse(new BezugsteuerEntryResource($entry), __('swiss_vat.messages.bezugsteuer_confirmed'));
    }

    #[OA\Post(path: '/api/v1/swiss-vat/bezugsteuer/{id}/exclude', operationId: 'excludeSwissVatBezugsteuer', summary: 'Exclude a detected Bezugsteuer entry', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Swiss VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Entry excluded')])]
    public function exclude(EmptySwissVatActionRequest $request, string $id): JsonResponse
    {
        $entry = $this->bezugsteuer->exclude($this->tenantId(), $id);

        return $this->successResponse(new BezugsteuerEntryResource($entry), __('swiss_vat.messages.bezugsteuer_excluded'));
    }
}
