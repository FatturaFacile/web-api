<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\ExpenseManagement\Application\Commands\ReconcileCorporateCardTransactionCommand;
use Modules\ExpenseManagement\Application\Commands\ReconcileCorporateCardTransactionHandler;
use Modules\ExpenseManagement\Application\Queries\GetCorporateCardUnmatchedTransactionsHandler;
use Modules\ExpenseManagement\Application\Queries\GetCorporateCardUnmatchedTransactionsQuery;
use Modules\ExpenseManagement\Http\Controllers\Concerns\InteractsWithExpenses;
use Modules\ExpenseManagement\Http\Requests\ImportCardTransactionsRequest;
use Modules\ExpenseManagement\Http\Requests\ListFiltersRequest;
use Modules\ExpenseManagement\Http\Requests\ReconcileCardRequest;
use Modules\ExpenseManagement\Http\Resources\CardTransactionResource;
use Modules\ExpenseManagement\Models\CorporateCardAccount;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Services\CardReconciliationService;
use OpenApi\Attributes as OA;

/**
 * Corporate cards: accounts, statement lines and their reconciliation with expense lines.
 */
final class CardController extends Controller
{
    use ApiResponse;
    use InteractsWithExpenses;

    #[OA\Get(path: '/api/v1/expense-management/card-accounts', operationId: 'expenseManagementCardAccounts', summary: 'Corporate card accounts', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], responses: [new OA\Response(response: 200, description: 'Card accounts')])]
    public function accounts(): JsonResponse
    {
        Gate::authorize('viewCards', ExpenseReport::class);

        return $this->successResponse(CorporateCardAccount::query()->where('is_active', true)->orderBy('holder_name')->get(['id', 'holder_name', 'card_last4', 'currency', 'issuer']));
    }

    #[OA\Get(path: '/api/v1/expense-management/card-transactions', operationId: 'expenseManagementCardTransactions', summary: 'Corporate card statement lines, by reconciliation status', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], responses: [new OA\Response(response: 200, description: 'Statement lines, paginated')])]
    public function transactions(ListFiltersRequest $request, GetCorporateCardUnmatchedTransactionsHandler $handler): JsonResponse
    {
        Gate::authorize('viewCards', ExpenseReport::class);

        $page = $handler->handle(new GetCorporateCardUnmatchedTransactionsQuery($request->input('status', 'unmatched'), $request->input('card_account_id'), $this->perPage()));

        return $this->successResponse(CardTransactionResource::collection($page->items()), $this->message('listed'), 200, [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
        ]);
    }

    #[OA\Post(path: '/api/v1/expense-management/card-transactions/import', operationId: 'expenseManagementImportCardTransactions', summary: 'Import card statement lines', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], responses: [new OA\Response(response: 201, description: 'Lines imported')])]
    public function import(ImportCardTransactionsRequest $request, CardReconciliationService $cards): JsonResponse
    {
        Gate::authorize('reconcile', ExpenseReport::class);

        $account = CorporateCardAccount::query()->findOrFail($request->input('card_account_id'));
        $imported = $cards->import($account, $request->validated('transactions'), $this->tenantId());

        return $this->successResponse(CardTransactionResource::collection($imported), $this->message('saved'), 201);
    }

    #[OA\Post(path: '/api/v1/expense-management/card-transactions/{id}/reconcile', operationId: 'expenseManagementReconcileCardTransaction', summary: 'Match, split, unmatch, ignore or dispute a card statement line', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Statement line reconciled')])]
    public function reconcile(ReconcileCardRequest $request, string $id, ReconcileCorporateCardTransactionHandler $handler): JsonResponse
    {
        Gate::authorize('reconcile', ExpenseReport::class);

        $transaction = $handler->handle(new ReconcileCorporateCardTransactionCommand($id, (string) $request->input('action'), $request->validated('matches', []) ?? [], $this->userId()));

        return $this->successResponse(new CardTransactionResource($transaction->load('account', 'lines.report:id,report_number')), $this->message('saved'));
    }
}
