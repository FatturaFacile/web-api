<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Http\Requests\StoreChartOfAccountRequest;
use Modules\GeneralLedger\Http\Resources\ChartOfAccountResource;
use Modules\GeneralLedger\Models\ChartOfAccount;

final class ChartOfAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id ?? (string) Str::uuid7();

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
        $tenantId = $request->user()?->tenant_id ?? (string) Str::uuid7();

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

        return response()->json([
            'data' => new ChartOfAccountResource($account),
        ]);
    }

    public function update(StoreChartOfAccountRequest $request, string $id): JsonResponse
    {
        $account = ChartOfAccount::findOrFail($id);
        $account->update($request->validated());

        return response()->json([
            'message' => 'Account updated successfully',
            'data' => new ChartOfAccountResource($account),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $account = ChartOfAccount::findOrFail($id);
        $account->delete();

        return response()->json([
            'message' => 'Account deleted successfully',
        ]);
    }

    public function hierarchy(Request $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id ?? (string) Str::uuid7();

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
