<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Associations\Support\TenantRules;

final class StoreMembershipRequest extends FormRequest
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
            'member_id' => ['required', 'uuid', TenantRules::exists('assoc_members')],
            'year_id' => ['required', 'uuid', TenantRules::exists('assoc_years')],
            'membership_type_id' => ['required', 'uuid', TenantRules::exists('assoc_membership_types')],
            'activate' => ['sometimes', 'boolean'],
        ];
    }
}
