<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnitOfMeasureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';
        $ignore = $this->route('id');

        return [
            'code' => [$presence, 'string', 'max:10', Rule::unique('units_of_measure', 'code')->where('tenant_id', $tenantId)->ignore($ignore)],
            'name' => [$presence, 'string', 'max:50'],
        ];
    }
}
