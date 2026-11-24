<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\AccountsPayable\Enums\OpenItemStatus;
use Modules\AccountsPayable\Http\Controllers\Concerns\ResolvesAccountsPayableContext;
use Modules\AccountsPayable\Http\Requests\HoldOpenItemRequest;
use Modules\AccountsPayable\Http\Requests\OpenItemIndexRequest;
use Modules\AccountsPayable\Http\Requests\ShowOpenItemRequest;
use Modules\AccountsPayable\Http\Requests\SkontoTermsOverrideRequest;
use Modules\AccountsPayable\Http\Requests\StoreOpenItemRequest;
use Modules\AccountsPayable\Http\Resources\OpenItemEventResource;
use Modules\AccountsPayable\Http\Resources\OpenItemResource;
use Modules\AccountsPayable\Services\OpenItemService;
use OpenApi\Attributes as OA;

/**
 * The supplier ledger (partitario fornitori): open items with their Skonto figures, disputes and administrative blocks.
 */
final class OpenItemController extends Controller
{
    use ApiResponse;
    use ResolvesAccountsPayableContext;

    public function __construct(private readonly OpenItemService $items) {}

    #[OA\Get(path: '/api/v1/accounts-payable/open-items', operationId: 'listApOpenItems', summary: 'Supplier open items with cash-discount figures', description: 'Open items (partite aperte) of the supplier invoices and credit notes. Each item carries skonto_eligible, skonto_percentage, skonto_deadline_date, skonto_days_remaining, skonto_savings_amount and net_payable_with_skonto at the as_of date, and payment_block when it cannot be paid.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'A page of open items')])]
    public function index(OpenItemIndexRequest $request): JsonResponse
    {
        $page = $this->items->paginate($this->tenantId(), $request->filters(), $request->asOf(), $request->perPage());

        return $this->successResponse(OpenItemResource::collection($page->items()), __('accounts_payable.messages.open_items_retrieved'), 200, [
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
        ]);
    }

    #[OA\Get(path: '/api/v1/accounts-payable/open-items/{id}', operationId: 'showApOpenItem', summary: 'One supplier open item', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The open item'), new OA\Response(response: 404, description: 'Unknown item')])]
    public function show(ShowOpenItemRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new OpenItemResource($this->items->find($this->tenantId(), $id, $request->asOf())), __('accounts_payable.messages.open_item_retrieved'));
    }

    #[OA\Post(path: '/api/v1/accounts-payable/open-items', operationId: 'createApOpenItem', summary: 'Register a manual supplier open item', description: 'A document that did not come as an electronic or Swiss QR invoice. It is not posted in the ledger by this module.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 201, description: 'The open item'), new OA\Response(response: 422, description: 'Validation error')])]
    public function store(StoreOpenItemRequest $request): JsonResponse
    {
        $item = $this->items->createManual($this->tenantId(), $request->validated(), $this->userId($request));

        return $this->successResponse(new OpenItemResource($this->items->find($this->tenantId(), (string) $item->id)), __('accounts_payable.messages.open_item_created'), 201);
    }

    #[OA\Get(path: '/api/v1/accounts-payable/open-items/{id}/history', operationId: 'listApOpenItemHistory', summary: 'Audit history of an open item', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The status changes, oldest first')])]
    public function history(string $id): JsonResponse
    {
        return $this->successResponse(OpenItemEventResource::collection($this->items->history($this->tenantId(), $id)), __('accounts_payable.messages.history_retrieved'));
    }

    #[OA\Post(path: '/api/v1/accounts-payable/open-items/{id}/dispute', operationId: 'disputeApOpenItem', summary: 'Put an open item in dispute', description: 'A disputed item leaves every payment proposal and cannot be authorized or paid until it is released.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The disputed item'), new OA\Response(response: 409, description: 'Already held or settled')])]
    public function dispute(HoldOpenItemRequest $request, string $id): JsonResponse
    {
        $item = $this->items->hold($this->tenantId(), $id, OpenItemStatus::Disputed, (string) $request->validated('reason'), $this->userId($request));

        return $this->successResponse(new OpenItemResource($item), __('accounts_payable.messages.open_item_disputed'));
    }

    #[OA\Post(path: '/api/v1/accounts-payable/open-items/{id}/block', operationId: 'blockApOpenItem', summary: 'Put an open item under administrative block', description: 'Same effect as a dispute, for administrative reasons (missing documents, supplier verification).', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The blocked item'), new OA\Response(response: 409, description: 'Already held or settled')])]
    public function block(HoldOpenItemRequest $request, string $id): JsonResponse
    {
        $item = $this->items->hold($this->tenantId(), $id, OpenItemStatus::Blocked, (string) $request->validated('reason'), $this->userId($request));

        return $this->successResponse(new OpenItemResource($item), __('accounts_payable.messages.open_item_blocked'));
    }

    #[OA\Post(path: '/api/v1/accounts-payable/open-items/{id}/release', operationId: 'releaseApOpenItem', summary: 'Release a disputed or blocked open item', description: 'The audited reason is kept in the history of the item.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The released item'), new OA\Response(response: 409, description: 'The item is not held')])]
    public function release(HoldOpenItemRequest $request, string $id): JsonResponse
    {
        $item = $this->items->release($this->tenantId(), $id, (string) $request->validated('reason'), $this->userId($request));

        return $this->successResponse(new OpenItemResource($item), __('accounts_payable.messages.open_item_released'));
    }

    #[OA\Put(path: '/api/v1/accounts-payable/open-items/{id}/skonto-terms', operationId: 'setApOpenItemSkontoTerms', summary: 'Override the cash-discount terms of one document', description: 'Both fields null remove the override: the supplier agreement or payment term applies again.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The item with the new figures')])]
    public function skontoTerms(SkontoTermsOverrideRequest $request, string $id): JsonResponse
    {
        $data = $request->validated();
        $percentage = $data['skonto_percentage'] ?? null;
        $item = $this->items->setSkontoTerms($this->tenantId(), $id, $percentage === null ? null : bcadd((string) $percentage, '0', 2), isset($data['skonto_days']) ? (int) $data['skonto_days'] : null, $this->userId($request));

        return $this->successResponse(new OpenItemResource($item), __('accounts_payable.messages.skonto_terms_saved'));
    }
}
