<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\FranchiseNetwork\Domain\Enums\SizeCluster;
use Modules\FranchiseNetwork\Domain\Enums\StoreStatus;
use Modules\FranchiseNetwork\Domain\Enums\StoreType;
use Modules\FranchiseNetwork\Http\Requests\Concerns\ScopesToTenant;

final class SaveStoreRequest extends FormRequest
{
    use ScopesToTenant;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:30', $this->tenantUnique('frn_stores', 'code')->ignore($this->route('id'))],
            'name' => [$required, 'string', 'max:160'],
            'store_type' => [$required, Rule::in(StoreType::values())],
            'status' => ['sometimes', Rule::in(StoreStatus::values())],
            'country' => [$required, 'string', Rule::in(['IT', 'CH'])],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:12'],
            'size_cluster' => ['sometimes', Rule::in(SizeCluster::values())],
            'opened_on' => ['nullable', 'date'],
            'area_manager_user_id' => ['nullable', 'uuid', Rule::exists('tenant_user', 'user_id')->where('tenant_id', app(TenantContext::class)->getTenantId())],
            'vat_number' => ['nullable', 'string', 'max:30'],
            'uid_number' => ['nullable', 'string', 'max:30'],
        ];
    }
}
