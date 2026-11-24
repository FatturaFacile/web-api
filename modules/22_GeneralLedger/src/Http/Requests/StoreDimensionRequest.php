<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A cost centre or profit centre: code unique in the tenant, name and active flag. On update every field is optional.
 */
final class StoreDimensionRequest extends FormRequest
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
        $table = str_contains((string) $this->route()?->uri(), 'profit-centers') ? 'profit_centers' : 'cost_centers';
        $id = $this->route('id');
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:50', Rule::unique($table, 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')->ignore($id)],
            'name' => [$required, 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
