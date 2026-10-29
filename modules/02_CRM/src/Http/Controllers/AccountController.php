<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\CRM\Contracts\CustomerHealthServiceInterface;
use Modules\CRM\Http\Resources\AccountResource;
use Modules\CRM\Http\Resources\CustomerHealthProfileResource;
use Modules\CRM\Models\Account;

final class AccountController extends Controller
{
    public function __construct(
        private readonly CustomerHealthServiceInterface $healthService,
        private readonly TenantContext $tenantContext
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $query = Account::where('tenant_id', $tenant->id);

        if ($request->has('tier')) {
            $query->where('tier', $request->query('tier'));
        }

        if ($request->has('churn_risk')) {
            $query->where('churn_risk', $request->query('churn_risk'));
        }

        $accounts = $query->orderBy('name')->paginate(25);

        return response()->json([
            'data' => AccountResource::collection($accounts),
            'meta' => [
                'current_page' => $accounts->currentPage(),
                'total' => $accounts->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'tier' => ['nullable', 'string', 'in:strategic,enterprise,mid_market,smb,standard'],
            'industry' => ['nullable', 'string', 'max:100'],
            'website' => ['nullable', 'string', 'max:255'],
        ]);

        $tenant = $this->tenantContext->getTenant();
        $account = Account::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $tenant->id,
            'name' => $request->input('name'),
            'account_number' => 'ACC-'.strtoupper(Str::random(6)),
            'tier' => $request->input('tier', 'standard'),
            'industry' => $request->input('industry'),
            'website' => $request->input('website'),
            'owner_user_id' => $request->user()?->id,
            'customer_health_index' => 100.00,
            'churn_risk' => 'low',
        ]);

        return response()->json(new AccountResource($account), 201);
    }

    public function show(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $account = Account::where('tenant_id', $tenant->id)->findOrFail($id);

        return response()->json(new AccountResource($account));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $account = Account::where('tenant_id', $tenant->id)->findOrFail($id);

        $account->update($request->only([
            'name', 'tier', 'industry', 'website', 'status', 'annual_revenue', 'employee_count', 'tags',
        ]));

        return response()->json(new AccountResource($account));
    }

    public function destroy(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $account = Account::where('tenant_id', $tenant->id)->findOrFail($id);
        $account->delete();

        return response()->json(null, 204);
    }

    public function health(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $account = Account::where('tenant_id', $tenant->id)->findOrFail($id);

        $profile = $this->healthService->evaluateHealth($account);

        return response()->json(new CustomerHealthProfileResource($profile));
    }
}
