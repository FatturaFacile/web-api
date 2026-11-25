<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\GdoTradeTerms\Application\Commands\CancelSettlementCommand;
use Modules\GdoTradeTerms\Application\Commands\CancelSettlementHandler;
use Modules\GdoTradeTerms\Application\Commands\ProposeSettlementCommand;
use Modules\GdoTradeTerms\Application\Commands\ProposeSettlementHandler;
use Modules\GdoTradeTerms\Application\Commands\ReconcilePromoInvoiceCommand;
use Modules\GdoTradeTerms\Application\Commands\ReconcilePromoInvoiceHandler;
use Modules\GdoTradeTerms\Application\Commands\ResolveSettlementDisputeCommand;
use Modules\GdoTradeTerms\Application\Commands\ResolveSettlementDisputeHandler;
use Modules\GdoTradeTerms\Application\Commands\SettleCreditNoteCommand;
use Modules\GdoTradeTerms\Application\Commands\SettleCreditNoteHandler;
use Modules\GdoTradeTerms\Application\Queries\GetSettlementDetailHandler;
use Modules\GdoTradeTerms\Application\Queries\GetSettlementDetailQuery;
use Modules\GdoTradeTerms\Application\Queries\GetSettlementsHandler;
use Modules\GdoTradeTerms\Application\Queries\GetSettlementsQuery;
use Modules\GdoTradeTerms\Http\Controllers\Concerns\InteractsWithGdo;
use Modules\GdoTradeTerms\Http\Requests\ListFiltersRequest;
use Modules\GdoTradeTerms\Http\Requests\PromoInvoiceRequest;
use Modules\GdoTradeTerms\Http\Requests\ProposeSettlementRequest;
use Modules\GdoTradeTerms\Http\Requests\ResolveDisputeRequest;
use Modules\GdoTradeTerms\Http\Requests\SettleCreditNoteRequest;
use Modules\GdoTradeTerms\Http\Resources\SettlementResource;
use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Models\GdoSettlement;
use OpenApi\Attributes as OA;

/**
 * Settlement runs of the contracts: proposal, credit note, reconciliation of the promotional invoice and disputes.
 */
final class SettlementController extends Controller
{
    use ApiResponse;
    use InteractsWithGdo;

    #[OA\Get(path: '/api/v1/gdo-trade-terms/settlements', operationId: 'gdoTradeTermsListSettlements', summary: 'List the settlement runs', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], responses: [new OA\Response(response: 200, description: 'Settlements, paginated')])]
    public function index(ListFiltersRequest $request, GetSettlementsHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', GdoSettlement::class);

        return $this->paged($handler->handle(new GetSettlementsQuery($request->input('status'), $request->input('contract_id'), $this->perPage())), SettlementResource::class);
    }

    #[OA\Post(path: '/api/v1/gdo-trade-terms/settlements', operationId: 'gdoTradeTermsProposeSettlement', summary: 'Propose a settlement: accrued against earned for a contract and period', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], responses: [new OA\Response(response: 201, description: 'Settlement proposed')])]
    public function store(ProposeSettlementRequest $request, ProposeSettlementHandler $handler): JsonResponse
    {
        Gate::authorize('settle', GdoSettlement::class);
        $data = $request->validated();
        GdoContract::query()->findOrFail($data['contract_id']);

        $settlement = $handler->handle(new ProposeSettlementCommand((string) $data['contract_id'], (string) $data['kind'], (string) $data['period_start'], (string) $data['period_end'], $this->userId()));

        return $this->successResponse(new SettlementResource($settlement->load('contract')), $this->message('proposed'), 201);
    }

    #[OA\Get(path: '/api/v1/gdo-trade-terms/settlements/{id}', operationId: 'gdoTradeTermsShowSettlement', summary: 'A settlement run with its lines', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Settlement detail')])]
    public function show(string $id, GetSettlementDetailHandler $handler): JsonResponse
    {
        $settlement = $handler->handle(new GetSettlementDetailQuery($id));
        Gate::authorize('view', $settlement);

        return $this->successResponse(new SettlementResource($settlement));
    }

    #[OA\Post(path: '/api/v1/gdo-trade-terms/settlements/{id}/credit-note', operationId: 'gdoTradeTermsSettleCreditNote', summary: 'Issue the credit note of the rebate lines and release their provision', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Credit note drafted')])]
    public function creditNote(SettleCreditNoteRequest $request, string $id, SettleCreditNoteHandler $handler): JsonResponse
    {
        Gate::authorize('settle', GdoSettlement::class);
        GdoSettlement::query()->findOrFail($id);

        $settlement = $handler->handle(new SettleCreditNoteCommand($id, (string) $request->validated('tax_rate'), $request->validated('settled_on'), $this->userId()));

        return $this->successResponse(new SettlementResource($settlement->load('contract')), $this->message('credit_note'));
    }

    #[OA\Post(path: '/api/v1/gdo-trade-terms/settlements/{id}/promo-invoice', operationId: 'gdoTradeTermsReconcilePromoInvoice', summary: 'Reconcile the promotional invoice of the retailer with the earned contributions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Invoice reconciled or disputed')])]
    public function promoInvoice(PromoInvoiceRequest $request, string $id, ReconcilePromoInvoiceHandler $handler): JsonResponse
    {
        Gate::authorize('settle', GdoSettlement::class);
        GdoSettlement::query()->findOrFail($id);

        $settlement = $handler->handle(new ReconcilePromoInvoiceCommand($id, $request->validated(), $this->userId()));

        return $this->successResponse(new SettlementResource($settlement->load('contract')), $this->message($settlement->status->value === 'disputed' ? 'disputed' : 'reconciled'));
    }

    #[OA\Post(path: '/api/v1/gdo-trade-terms/settlements/{id}/resolve-dispute', operationId: 'gdoTradeTermsResolveDispute', summary: 'Accept the claim of the retailer or reject it', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Dispute resolved')])]
    public function resolveDispute(ResolveDisputeRequest $request, string $id, ResolveSettlementDisputeHandler $handler): JsonResponse
    {
        Gate::authorize('settle', GdoSettlement::class);
        GdoSettlement::query()->findOrFail($id);

        $settlement = $handler->handle(new ResolveSettlementDisputeCommand($id, (string) $request->validated('resolution'), $request->validated('notes'), $this->userId()));

        return $this->successResponse(new SettlementResource($settlement->load('contract')), $this->message('dispute_resolved'));
    }

    #[OA\Post(path: '/api/v1/gdo-trade-terms/settlements/{id}/cancel', operationId: 'gdoTradeTermsCancelSettlement', summary: 'Cancel a settlement that settled nothing', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Settlement cancelled')])]
    public function cancel(string $id, CancelSettlementHandler $handler): JsonResponse
    {
        Gate::authorize('settle', GdoSettlement::class);
        GdoSettlement::query()->findOrFail($id);

        return $this->successResponse(new SettlementResource($handler->handle(new CancelSettlementCommand($id, $this->userId()))->load('contract')), $this->message('cancelled'));
    }
}
