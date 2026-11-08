<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A leave request an employee submits for themselves: the employee is the one linked to the user.
 */
final class SubmitOwnLeaveRequest extends FormRequest
{
    /**
     * The controller resolves the own employee record.
     */
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
            'leave_type_id' => ['required', 'uuid', TenantScopedRules::exists('hr_leave_types')],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'total_hours' => ['required', 'decimal:0,2', 'min:0.5', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'medical_certificate_ref' => ['nullable', 'string', 'max:120'],
        ];
    }
}
