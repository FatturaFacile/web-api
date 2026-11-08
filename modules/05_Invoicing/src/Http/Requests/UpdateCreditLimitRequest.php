<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Credit limit of a customer of the active company; 0 means that no limit is set (exposure is not checked).
 */
final class UpdateCreditLimitRequest extends FormRequest
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
            'customer_id' => ['required', 'uuid', $this->tenantOwns('contacts')],
            'credit_limit' => ['required', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
        ];
    }
}
