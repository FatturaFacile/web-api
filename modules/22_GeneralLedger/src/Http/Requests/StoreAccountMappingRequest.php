<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class StoreAccountMappingRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'source_account_id' => ['required', 'uuid', $this->tenantExists('chart_of_accounts')],
            'target_account_id' => ['nullable', 'uuid', $this->tenantExists('chart_of_accounts')],
            'source_standard' => ['nullable', 'string', 'max:50'],
            'target_standard' => ['required', 'string', 'max:50'],
            'target_account_code' => ['required', 'string', 'max:50'],
            'target_account_name' => ['required', 'string', 'max:255'],
            'mapping_type' => ['nullable', 'string', 'in:direct,split,aggregation,reclassification'],
            'is_bidirectional' => ['nullable', 'boolean'],
            'allocation_percentage' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
