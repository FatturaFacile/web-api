<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Treasury\Http\Requests\StoreBankAccountRequest;
use Modules\Treasury\Http\Resources\BankAccountResource;
use Modules\Treasury\Models\BankAccount;

final class BankAccountController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $query = BankAccount::where('tenant_id', $tenantId);

        if ($request->has('currency')) {
            $query->where('currency', strtoupper($request->query('currency')));
        }
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $accounts = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(BankAccountResource::collection($accounts)->response()->getData(true));
    }

    public function store(StoreBankAccountRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $data = $request->validated();
        $tenantId = $tenantContext->getTenantId();

        $account = BankAccount::create(array_merge($data, [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'current_balance' => $data['opening_balance'] ?? 0.00,
            'available_balance' => $data['opening_balance'] ?? 0.00,
            'is_active' => true,
        ]));

        return response()->json(new BankAccountResource($account), 201);
    }

    public function show(string $id): JsonResponse
    {
        $account = BankAccount::findOrFail($id);

        return response()->json(new BankAccountResource($account));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $account = BankAccount::findOrFail($id);
        $account->update($request->only([
            'bank_name',
            'branch_name',
            'overdraft_limit',
            'credit_line_limit',
            'is_default',
            'is_active',
        ]));

        return response()->json(new BankAccountResource($account));
    }

    public function destroy(string $id): JsonResponse
    {
        $account = BankAccount::findOrFail($id);
        $account->delete();

        return response()->json(['message' => 'Bank account soft deleted successfully.']);
    }
}
