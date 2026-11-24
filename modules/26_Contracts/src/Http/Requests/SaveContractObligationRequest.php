<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Contracts\Models\ContractObligation;

/**
 * Validates an obligation or milestone of a contract (access is decided by module.access).
 */
final class SaveContractObligationRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware.
     */
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
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(ContractObligation::Types)],
            'description' => ['nullable', 'string', 'max:5000'],
            'responsible_party' => ['nullable', Rule::in(['own', 'counterparty'])],
            'owner_user_id' => ['nullable', 'uuid', Rule::exists('tenant_user', 'user_id')->where('tenant_id', app(TenantContext::class)->getTenantId())],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['nullable', 'decimal:0,2', 'min:0', 'required_with:currency'],
            'currency' => ['nullable', 'string', 'size:3', 'required_with:amount'],
            'remind_days_before' => ['nullable', 'integer', 'min:0', 'max:365'],
        ];
    }
}
