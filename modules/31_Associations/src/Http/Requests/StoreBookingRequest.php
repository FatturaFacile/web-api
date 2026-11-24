<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Associations\Support\TenantRules;

final class StoreBookingRequest extends FormRequest
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
            'resource_id' => ['required', 'uuid', TenantRules::exists('assoc_resources')],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'member_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_members')],
            'course_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_courses')],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
