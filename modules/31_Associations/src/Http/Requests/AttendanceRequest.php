<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Models\Attendance;
use Modules\Associations\Support\TenantRules;

final class AttendanceRequest extends FormRequest
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
            'date' => ['required', 'date'],
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.member_id' => ['required', 'uuid', 'distinct', TenantRules::exists('assoc_members')],
            'entries.*.status' => ['required', Rule::in([Attendance::Present, Attendance::Absent])],
        ];
    }
}
