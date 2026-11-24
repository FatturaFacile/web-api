<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * References to other records are validated against the active tenant (TenantScopedRules).
 */
final class GenerateForecastRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => ['required', 'string', 'max:20'],
            'user_id' => ['nullable', 'uuid', $this->tenantMember()],
            'currency' => ['nullable', 'string', 'size:3'],
            'quota_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
