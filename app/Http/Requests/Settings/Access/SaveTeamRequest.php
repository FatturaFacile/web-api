<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings\Access;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A team: name, description and the optional custom role its new members receive.
 */
class SaveTeamRequest extends FormRequest
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
        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'custom_role_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('tenant_custom_roles', 'id')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')],
        ];
    }
}
