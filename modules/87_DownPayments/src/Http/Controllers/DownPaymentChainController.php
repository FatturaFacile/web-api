<?php

declare(strict_types=1);

namespace Modules\DownPayments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\DownPayments\Http\Controllers\Concerns\InteractsWithDownPayments;
use Modules\DownPayments\Http\Requests\ChainIndexRequest;
use Modules\DownPayments\Http\Requests\CreateChainRequest;
use Modules\DownPayments\Http\Requests\EmitInstallmentRequest;
use Modules\DownPayments\Http\Requests\PreviewPlanRequest;
use Modules\DownPayments\Http\Resources\ChainResource;
use Modules\DownPayments\Models\DownPaymentChain;
use Modules\DownPayments\Services\ChainReadService;
use Modules\DownPayments\Services\ChainService;
use Modules\DownPayments\Services\InstallmentPlanService;
use OpenApi\Attributes as OA;

/**
 * Down-payment chains (Mod 203): list and graph of the chains, the installment plan wizard, the creation from a sales order, quote or
 * contract and the emission of the installments (advance invoice, pro-forma request, earnest money or security deposit receipt).
 */
final class DownPaymentChainController extends Controller
{
    use ApiResponse;
    use InteractsWithDownPayments;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(
        private readonly ChainService $chains,
        private readonly ChainReadService $reader,
        private readonly InstallmentPlanService $plan,
    ) {}

    #[OA\Get(path: '/api/v1/down-payment-chains', operationId: 'listDownPaymentChains', summary: 'Paginated down-payment chains, filtered by customer, status, source and country (IT/CH)', security: self::SECURITY, tags: ['DownPayments'], responses: [new OA\Response(response: 200, description: 'Chains')])]
    public function index(ChainIndexRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', DownPaymentChain::class);
        $filters = $request->validated();
        $page = $this->reader->paginate($this->tenantId(), $filters, (int) ($filters['page'] ?? 1), $this->perPage());

        return $this->successResponse(ChainResource::collection($page->items()), $this->message('listed'), 200, [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
        ]);
    }

    #[OA\Get(path: '/api/v1/down-payment-chains/{id}', operationId: 'showDownPaymentChain', summary: 'The full graph of a chain: plan, links with the per-VAT breakdown, deposits, deduction lines, matrix and journal entries', security: self::SECURITY, tags: ['DownPayments'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The chain'), new OA\Response(response: 404, description: 'Not found')])]
    public function show(string $id): JsonResponse
    {
        $chain = $this->chain($id);
        Gate::authorize('view', $chain);

        return $this->successResponse($this->reader->detail($chain), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/down-payment-chains/lookups', operationId: 'downPaymentLookups', summary: 'The plan templates of the wizard with their installments', security: self::SECURITY, tags: ['DownPayments'], responses: [new OA\Response(response: 200, description: 'Lookups')])]
    public function lookups(): JsonResponse
    {
        Gate::authorize('viewAny', DownPaymentChain::class);

        return $this->successResponse(['presets' => $this->plan->presets()], $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/down-payment-chains/preview-plan', operationId: 'previewDownPaymentPlan', summary: 'Previews an installment plan with its cent-exact split over the VAT rates of the source, without storing anything', security: self::SECURITY, tags: ['DownPayments'], responses: [new OA\Response(response: 200, description: 'The plan'), new OA\Response(response: 422, description: 'Plan refused')])]
    public function previewPlan(PreviewPlanRequest $request): JsonResponse
    {
        Gate::authorize('create', DownPaymentChain::class);

        return $this->successResponse($this->chains->previewPlan($this->tenantId(), $request->validated()), $this->message('previewed'));
    }

    #[OA\Post(path: '/api/v1/down-payment-chains/from-source', operationId: 'createDownPaymentChainFromSource', summary: 'Opens a chain from a sales order, quote or contract and runs the installment plan wizard', security: self::SECURITY, tags: ['DownPayments'], responses: [new OA\Response(response: 201, description: 'The chain'), new OA\Response(response: 409, description: 'The source already has a chain'), new OA\Response(response: 422, description: 'Rule refused')])]
    public function store(CreateChainRequest $request): JsonResponse
    {
        Gate::authorize('create', DownPaymentChain::class);
        $chain = $this->chains->create($this->tenantId(), $request->validated(), $this->userId());

        return $this->createdResponse($this->reader->detail($chain), $this->message('created'));
    }

    #[OA\Post(path: '/api/v1/down-payment-chains/{id}/cancel', operationId: 'cancelDownPaymentChain', summary: 'Cancels a chain that has not issued anything', security: self::SECURITY, tags: ['DownPayments'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The chain')])]
    public function cancel(string $id): JsonResponse
    {
        $chain = $this->chain($id);
        Gate::authorize('update', $chain);

        return $this->successResponse($this->reader->detail($this->chains->cancel($chain)), $this->message('cancelled'));
    }

    #[OA\Post(path: '/api/v1/down-payment-chains/{id}/installments/{installmentId}/emit', operationId: 'emitDownPaymentInstallment', summary: 'Emits an installment: the TD02 advance invoice, the pro-forma advance request, the earnest money receipt or the security deposit receipt', security: self::SECURITY, tags: ['DownPayments'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'installmentId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'What was emitted'), new OA\Response(response: 422, description: 'Rule refused')])]
    public function emit(EmitInstallmentRequest $request, string $id, string $installmentId): JsonResponse
    {
        $chain = $this->chain($id);
        Gate::authorize('emit', $chain);
        $result = $this->chains->emit($chain, $this->installment($chain, $installmentId), $request->validated(), $this->userId());

        return $this->successResponse([...$result, 'chain' => $this->reader->detail($chain->refresh())], $this->message('emitted'));
    }
}
