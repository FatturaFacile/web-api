<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CRM\Models\LeadActivityEvent;

final class StoreLeadActivityEventRequest extends FormRequest
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
            'type' => ['required', 'string', Rule::in(LeadActivityEvent::TYPES)],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:+5 minutes'],
            'metadata' => ['nullable', 'array'],
            'external_id' => ['nullable', 'string', 'max:191'],
        ];
    }
}
