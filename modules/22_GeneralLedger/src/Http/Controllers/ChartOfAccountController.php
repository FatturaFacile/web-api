<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Http\Requests\StoreChartOfAccountRequest;
use Modules\GeneralLedger\Http\Resources\ChartOfAccountResource;
use Modules\GeneralLedger\Models\ChartOfAccount;

final class ChartOfAccountController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $accounts = ChartOfAccount::where('tenant_id', $tenantId)
            ->when($request->query('type'), fn ($q, $type) => $q->where('account_type', $type))
            ->when($request->query('active'), fn ($q, $act) => $q->where('is_active', filter_var($act, FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('account_code')
            ->paginate((int) $request->query('per_page', 50));

        return response()->json([
            'data' => ChartOfAccountResource::collection($accounts),
            'meta' => [
                'current_page' => $accounts->currentPage(),
                'last_page' => $accounts->lastPage(),
                'total' => $accounts->total(),
            ],
        ]);
    }

    public function store(StoreChartOfAccountRequest $request): JsonResponse
    {
        $this->authorize('create', ChartOfAccount::class);

        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $account = ChartOfAccount::create(array_merge($request->validated(), [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
        ]));

        return response()->json([
            'message' => 'Account created successfully',
            'data' => new ChartOfAccountResource($account),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $account = ChartOfAccount::with('subAccounts')->findOrFail($id);
        $this->authorize('view', $account);

        return response()->json([
            'data' => new ChartOfAccountResource($account),
        ]);
    }

    public function update(StoreChartOfAccountRequest $request, string $id): JsonResponse
    {
        $account = ChartOfAccount::findOrFail($id);
        $this->authorize('update', $account);

        $account->update($request->validated());

        return response()->json([
            'message' => 'Account updated successfully',
            'data' => new ChartOfAccountResource($account),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $account = ChartOfAccount::findOrFail($id);
        $this->authorize('delete', $account);

        $account->delete();

        return response()->json([
            'message' => 'Account deleted successfully',
        ]);
    }

    public function hierarchy(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $rootAccounts = ChartOfAccount::where('tenant_id', $tenantId)
            ->whereNull('parent_account_id')
            ->with('subAccounts')
            ->orderBy('account_code')
            ->get();

        return response()->json([
            'data' => ChartOfAccountResource::collection($rootAccounts),
        ]);
    }
}
