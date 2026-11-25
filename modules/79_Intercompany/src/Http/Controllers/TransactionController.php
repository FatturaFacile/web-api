<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\Intercompany\Enums\MirrorMode;
use Modules\Intercompany\Http\Requests\MirrorTransactionRequest;
use Modules\Intercompany\Http\Requests\ReverseTransactionRequest;
use Modules\Intercompany\Http\Requests\ScanTransactionsRequest;
use Modules\Intercompany\Http\Requests\VersionedRequest;
use Modules\Intercompany\Http\Resources\SyncEventResource;
use Modules\Intercompany\Http\Resources\TransactionResource;
use Modules\Intercompany\Models\SyncEvent;
use Modules\Intercompany\Models\TransactionLink;
use Modules\Intercompany\Services\MirrorPostingService;
use Modules\Intercompany\Services\PaymentSyncService;
use Modules\Intercompany\Support\GroupScope;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Intercompany transactions: the documents issued between the entities of a group, their mirrors and the payment sync.
 */
final class TransactionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly GroupScope $scope,
        private readonly MirrorPostingService $mirror,
        private readonly PaymentSyncService $payments,
    ) {}

    #[OA\Get(path: '/api/v1/intercompany/groups/{groupId}/transactions', operationId: 'intercompanyListTransactions', summary: 'Intercompany transactions of a group', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'groupId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Paginated transactions with a summary')])]
    public function index(Request $request, string $groupId): JsonResponse
    {
        Gate::authorize('intercompany.view');
        $group = $this->scope->group($groupId);
        $this->scope->assertReadable($request->user(), $group);

        $query = TransactionLink::query()->where('group_id', $group->id)
            ->when($request->query('status'), fn ($q, $value) => $q->where('status', $value))
            ->when($request->query('payment_status'), fn ($q, $value) => $q->where('payment_status', $value))
            ->when($request->query('source_tenant_id'), fn ($q, $value) => $q->where('source_tenant_id', $value))
            ->when($request->query('mirror_tenant_id'), fn ($q, $value) => $q->where('mirror_tenant_id', $value));

        $page = (clone $query)->orderByDesc('document_date')->orderByDesc('created_at')->paginate(min(max($request->integer('per_page', 25), 1), 100));
        $all = TransactionLink::query()->where('group_id', $group->id)->get(['status', 'payment_status', 'currency', 'gross_amount']);
        $byCurrency = [];

        foreach ($all as $row) {
            $byCurrency[$row->currency] = bcadd($byCurrency[$row->currency] ?? '0', (string) $row->gross_amount, 4);
        }

        $meta = [
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
            'summary' => [
                'total' => $all->count(),
                'drafted' => $all->where('status', 'drafted')->count(),
                'confirmed' => $all->where('status', 'confirmed')->count(),
                'reversed' => $all->where('status', 'reversed')->count(),
                'failed' => $all->where('status', 'failed')->count(),
                'unpaid' => $all->where('payment_status', 'unpaid')->count(),
                'amount_by_currency' => $byCurrency,
            ],
        ];

        return $this->successResponse(TransactionResource::collection($page->items()), 'Intercompany transactions', Response::HTTP_OK, $meta);
    }

    #[OA\Get(path: '/api/v1/intercompany/transactions/{id}', operationId: 'intercompanyShowTransaction', summary: 'An intercompany transaction with its sync log', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Transaction')])]
    public function show(Request $request, string $id): JsonResponse
    {
        Gate::authorize('intercompany.view');
        [$link, $group] = $this->load($request, $id);
        $events = SyncEvent::query()->where('transaction_id', $link->id)->orderBy('occurred_at')->orderBy('id')->get();

        return $this->successResponse([...(new TransactionResource($link))->resolve($request), 'events' => SyncEventResource::collection($events)->resolve($request)], 'Intercompany transaction');
    }

    #[OA\Post(path: '/api/v1/intercompany/groups/{groupId}/transactions/scan', operationId: 'intercompanyScanTransactions', summary: 'Mirror the documents that have no link yet', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'groupId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Scan result')])]
    public function scan(ScanTransactionsRequest $request, string $groupId): JsonResponse
    {
        $group = $this->scope->group($groupId);
        $from = $request->validated('from') ? CarbonImmutable::parse($request->validated('from')) : null;
        $to = $request->validated('to') ? CarbonImmutable::parse($request->validated('to')) : null;
        $result = $this->mirror->scan($request->user(), $group, $from, $to);

        return $this->successResponse([
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
            'transactions' => TransactionResource::collection($result['transactions'])->resolve($request),
        ], 'Scan completed');
    }

    #[OA\Post(path: '/api/v1/intercompany/transactions/{id}/mirror', operationId: 'intercompanyMirrorTransaction', summary: 'Create or retry the mirror of a transaction', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Transaction')])]
    public function mirror(MirrorTransactionRequest $request, string $id): JsonResponse
    {
        [$link, $group] = $this->load($request, $id);
        $mode = $request->validated('mode') ? MirrorMode::from((string) $request->validated('mode')) : null;

        return $this->successResponse(new TransactionResource($this->mirror->mirror($group, $link, $request->user(), $mode, ($request->integer('expected_version') ?: null))), 'Mirror applied');
    }

    #[OA\Post(path: '/api/v1/intercompany/transactions/{id}/confirm', operationId: 'intercompanyConfirmTransaction', summary: 'Confirm a draft mirror', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Transaction')])]
    public function confirm(VersionedRequest $request, string $id): JsonResponse
    {
        [$link, $group] = $this->load($request, $id);

        return $this->successResponse(new TransactionResource($this->mirror->confirm($group, $link, $request->user(), ($request->integer('expected_version') ?: null))), 'Mirror confirmed');
    }

    #[OA\Post(path: '/api/v1/intercompany/transactions/{id}/reverse', operationId: 'intercompanyReverseTransaction', summary: 'Reverse the mirror of a transaction', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Transaction')])]
    public function reverse(ReverseTransactionRequest $request, string $id): JsonResponse
    {
        [$link, $group] = $this->load($request, $id);

        return $this->successResponse(new TransactionResource($this->mirror->reverse($group, $link, (string) $request->validated('reason'), $request->user(), ($request->integer('expected_version') ?: null))), 'Mirror reversed');
    }

    #[OA\Post(path: '/api/v1/intercompany/transactions/{id}/sync-payment', operationId: 'intercompanySyncPayment', summary: 'Carry the payment of the source document to its mirror', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Transaction')])]
    public function syncPayment(VersionedRequest $request, string $id): JsonResponse
    {
        [$link, $group] = $this->load($request, $id);

        return $this->successResponse(new TransactionResource($this->payments->sync($group, $link, $request->user())), 'Payment synchronised');
    }

    /**
     * @return array{0: TransactionLink, 1: ConsolidationGroup}
     */
    private function load(Request $request, string $id): array
    {
        $link = TransactionLink::query()->findOrFail($id);
        $group = $this->scope->group((string) $link->group_id);
        $this->scope->assertReadable($request->user(), $group);

        return [$link, $group];
    }
}
