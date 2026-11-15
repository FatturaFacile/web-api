<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\PracticeManagement\Http\Requests\PracticeRequest;
use Modules\PracticeManagement\Http\Resources\PracticeResource;
use Modules\PracticeManagement\Models\PracticeTrustTransaction;
use Modules\PracticeManagement\Services\TrustLedgerPostingService;
use Modules\PracticeManagement\Services\TrustLedgerService;
use OpenApi\Attributes as OA;

/**
 * Client funds (trust) ledger: deposits, withdrawals, transfers to fees and reversals. Never goes below zero.
 */
final class TrustLedgerController extends Controller
{
    public function __construct(private readonly TrustLedgerService $ledger, private readonly TrustLedgerPostingService $posting) {}

    #[OA\Get(path: '/api/v1/practice/trust-ledger', operationId: 'listPracticeTrustLedger', description: 'Return the rows of the client funds ledger, latest first, optionally of one client or one matter.', summary: 'List client funds ledger', security: [['bearerAuth' => []]], tags: ['Practice Management'], parameters: [
        new OA\Parameter(name: 'client_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'legal_case_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Ledger rows', content: new OA\JsonContent(ref: '#/components/schemas/PracticeDataResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function index(PracticeRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PracticeTrustTransaction::class);

        return PracticeResource::collection(PracticeTrustTransaction::query()
            ->with(['client', 'legalCase', 'posting'])
            ->when($request->validated('client_id') !== null, fn ($query) => $query->where('client_id', $request->validated('client_id')))
            ->when($request->validated('legal_case_id') !== null, fn ($query) => $query->where('legal_case_id', $request->validated('legal_case_id')))
            ->orderByDesc('occurred_on')->orderByDesc('id')->gridPaginate(RequestedPerPage::resolve(25)));
    }

    #[OA\Get(path: '/api/v1/practice/trust-ledger/balances', operationId: 'practiceTrustBalances', description: 'Return the balance of the funds held for each client, with the split per matter.', summary: 'Client funds balances', security: [['bearerAuth' => []]], tags: ['Practice Management'], responses: [
        new OA\Response(response: 200, description: 'Balances per client', content: new OA\JsonContent(ref: '#/components/schemas/PracticeDataResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
    ])]
    public function balances(): JsonResponse
    {
        $this->authorize('viewAny', PracticeTrustTransaction::class);

        return response()->json(['data' => $this->ledger->balances()]);
    }

    #[OA\Post(path: '/api/v1/practice/trust-ledger', operationId: 'storePracticeTrustTransaction', description: 'Post a deposit, withdrawal or transfer to fees on the client funds ledger. A withdrawal or transfer that would take the balance of the client (or of the matter) below zero is refused with 422.', summary: 'Post client funds transaction', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PracticeTrustTransactionRequest')), tags: ['Practice Management'], responses: [
        new OA\Response(response: 201, description: 'Transaction posted', content: new OA\JsonContent(ref: '#/components/schemas/PracticeRecordResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function store(PracticeRequest $request): JsonResponse
    {
        $this->authorize('create', PracticeTrustTransaction::class);

        return (new PracticeResource($this->ledger->record($request->validated(), $request->user()?->getKey())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/practice/trust-ledger/{id}/post', operationId: 'postPracticeTrustTransaction', description: 'Post a client funds row to the general ledger through its posting contract. A deposit debits the bank account and credits the client funds account, a withdrawal does the opposite, a transfer to fees debits the client funds account and credits the fee revenue account, and a reversal reverses the entry of the row it reverses. A row is posted once.', summary: 'Post client funds row to the general ledger', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PracticeTrustPostRequest')), tags: ['Practice Management'], parameters: [new OA\Parameter(ref: '#/components/parameters/PracticeId')], responses: [
        new OA\Response(response: 201, description: 'Row posted', content: new OA\JsonContent(ref: '#/components/schemas/PracticeRecordResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function post(PracticeRequest $request, string $id): JsonResponse
    {
        $transaction = PracticeTrustTransaction::query()->findOrFail($id);
        $this->authorize('update', $transaction);

        return (new PracticeResource($this->posting->post($transaction, $request->validated(), $request->user()?->getKey())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/practice/trust-ledger/{id}/reconcile', operationId: 'reconcilePracticeTrustTransaction', description: 'Match a posted client funds row that moved money with a bank statement line of the same amount: a credit for a deposit, a debit for a withdrawal. A transfer to fees moves no money through the bank.', summary: 'Reconcile client funds row with the bank', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PracticeTrustReconcileRequest')), tags: ['Practice Management'], parameters: [new OA\Parameter(ref: '#/components/parameters/PracticeId')], responses: [
        new OA\Response(response: 200, description: 'Row reconciled', content: new OA\JsonContent(ref: '#/components/schemas/PracticeRecordResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function reconcile(PracticeRequest $request, string $id): PracticeResource
    {
        $transaction = PracticeTrustTransaction::query()->findOrFail($id);
        $this->authorize('update', $transaction);

        return new PracticeResource($this->posting->reconcile($transaction, $request->validated('bank_statement_line_id')));
    }

    #[OA\Post(path: '/api/v1/practice/trust-ledger/{id}/reverse', operationId: 'reversePracticeTrustTransaction', description: 'Post the opposite row of a ledger row. A row is reversed once; a deposit can be reversed only while the balance still covers it.', summary: 'Reverse client funds transaction', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'description', type: 'string', nullable: true)])), tags: ['Practice Management'], parameters: [new OA\Parameter(ref: '#/components/parameters/PracticeId')], responses: [
        new OA\Response(response: 201, description: 'Reversal posted', content: new OA\JsonContent(ref: '#/components/schemas/PracticeRecordResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function reverse(PracticeRequest $request, string $id): JsonResponse
    {
        $transaction = PracticeTrustTransaction::query()->findOrFail($id);
        $this->authorize('update', $transaction);

        return (new PracticeResource($this->ledger->reverse($transaction, $request->validated('description'), $request->user()?->getKey())))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
