<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One-click handoff of a ticket to a field service work order. Every field is optional: the ticket supplies the rest.
 */
final class HandoffToFieldServiceRequest extends FormRequest
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
            'work_type' => ['sometimes', 'string', 'in:corrective,preventive,inspection,installation,emergency,commissioning'],
            'scheduled_start' => ['sometimes', 'nullable', 'date'],
            'scheduled_end' => ['sometimes', 'nullable', 'date', 'after_or_equal:scheduled_start'],
            'location_address' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
