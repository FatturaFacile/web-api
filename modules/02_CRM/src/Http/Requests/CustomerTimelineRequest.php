<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CRM\Services\Customer360Service;

/**
 * Filters of the customer timeline: touchpoint types, date window and size.
 */
final class CustomerTimelineRequest extends FormRequest
{
    /**
     * Access is decided by the module.access:crm middleware.
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
            'types' => ['nullable', 'array'],
            'types.*' => ['string', Rule::in(Customer360Service::TIMELINE_TYPES)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
