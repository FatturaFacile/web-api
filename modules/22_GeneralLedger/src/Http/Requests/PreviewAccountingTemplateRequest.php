<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The inputs that fill a template: the net amount, the tax rate when the template taxes, and one account per account
 * parameter.
 */
final class PreviewAccountingTemplateRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'gt:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'accounts' => ['nullable', 'array'],
            'accounts.*' => ['string', 'max:60'],
            'cost_center_id' => ['nullable', 'uuid', $this->tenantOwns('cost_centers')],
        ];
    }
}
