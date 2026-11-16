<?php

declare(strict_types=1);

namespace Modules\CorporateGroup\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\CorporateGroup\Models\Company;
use Modules\CorporateGroup\Models\CompanyAccountingSetting;
use Modules\CorporateGroup\Models\CompanyMember;
use Modules\CorporateGroup\Models\CompanySite;
use Modules\CorporateGroup\Support\CorporatePermissions as P;

final class CompanyController extends CorporateController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany($request, P::COMPANIES_VIEW, null);
        $ids = P::accessibleCompanyIds($request->user());

        $companies = Company::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('code')
            ->get();

        return response()->json(['data' => $companies]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(P::isTenantWide($request->user()), 403, 'Only tenant owners and admins can create companies.');
        $tenantId = $this->tenantId($request);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('companies', 'code')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:30'],
            'tax_code' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'size:2'],
            'currency' => ['nullable', 'string', 'size:3'],
            'parent_company_id' => ['nullable', 'uuid', Rule::exists('companies', 'id')->where('tenant_id', $tenantId)],
            'consolidation_method' => ['nullable', Rule::in(['full', 'proportional', 'equity'])],
            'ownership_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'shares_master_data' => ['nullable', 'boolean'],
        ]);

        $company = Company::create($data + ['tenant_id' => $tenantId]);

        return response()->json(['data' => $company->refresh()], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->authorizeCompany($request, P::COMPANIES_VIEW, $id);

        return response()->json(['data' => Company::query()->with(['sites', 'accountingSettings'])->findOrFail($id)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorizeCompany($request, P::COMPANIES_MANAGE, $id);
        $company = Company::query()->findOrFail($id);

        $data = $request->validate([
            'legal_name' => ['sometimes', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:30'],
            'tax_code' => ['nullable', 'string', 'max:30'],
            'parent_company_id' => ['nullable', 'uuid', Rule::exists('companies', 'id')->where('tenant_id', $company->tenant_id), Rule::notIn([$company->id])],
            'consolidation_method' => ['sometimes', Rule::in(['full', 'proportional', 'equity'])],
            'ownership_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'shares_master_data' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $company->update($data);

        return response()->json(['data' => $company->refresh()]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authorizeCompany($request, P::COMPANIES_MANAGE, $id);
        Company::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    // Sites -------------------------------------------------------------------------------------------------------

    public function sites(Request $request, string $id): JsonResponse
    {
        $this->authorizeCompany($request, P::COMPANIES_VIEW, $id);
        Company::query()->findOrFail($id);

        return response()->json(['data' => CompanySite::query()->where('company_id', $id)->orderBy('code')->get()]);
    }

    public function storeSite(Request $request, string $id): JsonResponse
    {
        $this->authorizeCompany($request, P::COMPANIES_MANAGE, $id);
        $company = Company::query()->findOrFail($id);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('company_sites', 'code')->where('company_id', $company->id)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'site_type' => ['nullable', Rule::in(['headquarters', 'branch', 'plant', 'warehouse', 'office'])],
            'address_line' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'province' => ['nullable', 'string', 'max:10'],
            'country' => ['nullable', 'string', 'size:2'],
            'cost_center_id' => ['nullable', 'uuid'],
            'profit_center_id' => ['nullable', 'uuid'],
        ]);

        $site = CompanySite::create($data + ['tenant_id' => $company->tenant_id, 'company_id' => $company->id]);

        return response()->json(['data' => $site->refresh()], 201);
    }

    public function destroySite(Request $request, string $id, string $siteId): JsonResponse
    {
        $this->authorizeCompany($request, P::COMPANIES_MANAGE, $id);
        CompanySite::query()->where('company_id', $id)->findOrFail($siteId)->delete();

        return response()->json(null, 204);
    }

    // Members (per-company permissions) -----------------------------------------------------------------------------

    public function members(Request $request, string $id): JsonResponse
    {
        $this->authorizeCompany($request, P::MEMBERS_MANAGE, $id);
        Company::query()->findOrFail($id);

        return response()->json(['data' => CompanyMember::query()->where('company_id', $id)->get()]);
    }

    public function storeMember(Request $request, string $id): JsonResponse
    {
        $this->authorizeCompany($request, P::MEMBERS_MANAGE, $id);
        $company = Company::query()->findOrFail($id);

        $data = $request->validate([
            'user_id' => ['required', 'uuid', Rule::exists('tenant_user', 'user_id')->where('tenant_id', $company->tenant_id)],
            'role' => ['required', Rule::in(array_keys(P::roleMatrix()))],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in([...P::all(), '*', 'companies.*'])],
        ]);

        $member = CompanyMember::query()->updateOrCreate(
            ['company_id' => $company->id, 'user_id' => $data['user_id']],
            ['tenant_id' => $company->tenant_id, 'role' => $data['role'], 'permissions' => $data['permissions'] ?? null],
        );

        return response()->json(['data' => $member->refresh()], 201);
    }

    public function destroyMember(Request $request, string $id, string $userId): JsonResponse
    {
        $this->authorizeCompany($request, P::MEMBERS_MANAGE, $id);
        CompanyMember::query()->where('company_id', $id)->where('user_id', $userId)->firstOrFail()->delete();

        return response()->json(null, 204);
    }

    // Intercompany accounts ------------------------------------------------------------------------------------------

    public function accountingSettings(Request $request, string $id): JsonResponse
    {
        $this->authorizeCompany($request, P::COMPANIES_VIEW, $id);
        Company::query()->findOrFail($id);

        return response()->json(['data' => CompanyAccountingSetting::query()->where('company_id', $id)->first()]);
    }

    public function updateAccountingSettings(Request $request, string $id): JsonResponse
    {
        $this->authorizeCompany($request, P::COMPANIES_MANAGE, $id);
        $company = Company::query()->findOrFail($id);

        // Accounts must belong to this company (or be tenant-wide generic accounts): never to a sibling company.
        $account = fn () => ['nullable', 'uuid', Rule::exists('chart_of_accounts', 'id')->where('tenant_id', $company->tenant_id)->where(fn ($q) => $q->where('company_id', $company->id)->orWhereNull('company_id'))];
        $data = $request->validate([
            'ic_receivable_account_id' => $account(),
            'ic_payable_account_id' => $account(),
            'ic_revenue_account_id' => $account(),
            'ic_expense_account_id' => $account(),
            'vat_payable_account_id' => $account(),
            'vat_receivable_account_id' => $account(),
        ]);

        $settings = CompanyAccountingSetting::query()->updateOrCreate(
            ['company_id' => $company->id],
            $data + ['tenant_id' => $company->tenant_id],
        );

        return response()->json(['data' => $settings->refresh()]);
    }
}
