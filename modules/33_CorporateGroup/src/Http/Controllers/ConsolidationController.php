<?php

declare(strict_types=1);

namespace Modules\CorporateGroup\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\CorporateGroup\Models\Company;
use Modules\CorporateGroup\Models\ConsolidationRun;
use Modules\CorporateGroup\Services\ConsolidationService;
use Modules\CorporateGroup\Support\CorporatePermissions as P;

final class ConsolidationController extends CorporateController
{
    public function __construct(private readonly ConsolidationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany($request, P::CONSOLIDATION_VIEW, null);
        $ids = P::accessibleCompanyIds($request->user());

        $runs = ConsolidationRun::query()->orderByDesc('created_at')->get()
            ->filter(fn (ConsolidationRun $run): bool => $ids === null || array_diff((array) $run->company_ids, $ids) === [])
            ->values();

        return response()->json(['data' => $runs]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date'],
            'company_ids' => ['nullable', 'array'],
            'company_ids.*' => ['uuid', Rule::exists('companies', 'id')->where('tenant_id', $tenantId)],
            'group_currency' => ['nullable', 'string', 'size:3'],
            'exchange_rates' => ['nullable', 'array'],
            'exchange_rates.*' => ['numeric', 'gt:0'],
        ]);

        // A consolidation reads the books of every included company: the user needs the permission on all of them.
        $ids = $data['company_ids'] ?? Company::query()->pluck('id')->map(fn ($id): string => (string) $id)->all();
        foreach ($ids as $id) {
            $this->authorizeCompany($request, P::CONSOLIDATION_RUN, $id);
        }
        $data['company_ids'] = $ids;

        return $this->guard(fn (): JsonResponse => response()->json([
            'data' => $this->service->report($this->service->consolidate($tenantId, $data, (string) $request->user()?->id)),
        ], 201));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $run = ConsolidationRun::query()->findOrFail($id);
        foreach ((array) $run->company_ids as $companyId) {
            $this->authorizeCompany($request, P::CONSOLIDATION_VIEW, (string) $companyId);
        }

        return response()->json(['data' => $this->service->report($run)]);
    }
}
