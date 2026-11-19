<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit;

use App\Domain\Audit\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters of the audit trail list and of the forensic export.
 */
class ListAuditLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event_type' => ['sometimes', 'nullable'],
            'event_type.*' => ['string', 'in:'.implode(',', AuditEventType::values())],
            'auditable_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'auditable_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'actor_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ip_address' => ['sometimes', 'nullable', 'string', 'max:45'],
            'changed_field' => ['sometimes', 'nullable', 'string', 'max:255'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return $this->safe()->except(['per_page', 'page']);
    }
}
