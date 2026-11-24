<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Associations\Support\TenantRules;

final class RenewMembershipRequest extends FormRequest
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
            'year_id' => ['required', 'uuid', TenantRules::exists('assoc_years')],
            'membership_type_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_membership_types')],
            'activate' => ['sometimes', 'boolean'],
        ];
    }
}
