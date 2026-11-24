<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Support\TenantRules;

final class RegisterDeviceRequest extends FormRequest
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
            'area_id' => ['required', 'uuid', TenantRules::exists('assoc_access_areas')],
            'name' => ['required', 'string', 'max:255'],
            'direction' => ['sometimes', Rule::in(['in', 'out', 'both'])],
        ];
    }
}
