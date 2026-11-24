<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\LeaveManagement\Models\DepartmentStaffingThreshold;

final class UpsertStaffingThresholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manageConfiguration', DepartmentStaffingThreshold::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'min_present_count' => ['required', 'integer', 'min:0', 'max:10000'],
            'min_present_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'enforce_hard_block' => ['nullable', 'boolean'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
