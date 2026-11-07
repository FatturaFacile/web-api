<?php

declare(strict_types=1);

namespace App\Http\Requests\Appointments;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppointmentRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId()
            ?? $this->user()?->tenant_id
            ?? $this->header('X-Tenant-Id');

        return [
            'contact_id' => [
                'sometimes',
                'uuid',
                Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id')),
            ],
            'ticket_id' => [
                'nullable',
                'uuid',
                Rule::exists('tickets', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id')),
            ],
            'status_id' => ['sometimes', 'uuid', $this->tenantExists('lookup_items')],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after:starts_at'],
            'all_day' => ['nullable', 'boolean'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'estimated_travel_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'estimated_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'technician_notes' => ['nullable', 'string'],
            'customer_notes' => ['nullable', 'string'],
            'is_portal_visible' => ['nullable', 'boolean'],
            'allow_conflicts' => ['nullable', 'boolean'],
            'attendees' => ['nullable', 'array'],
            'attendees.*.user_id' => [
                'required_with:attendees',
                'uuid',
                Rule::exists('tenant_user', 'user_id')->where('tenant_id', $tenantId),
            ],
            'attendees.*.role' => ['nullable', 'string', Rule::in(['lead', 'technician', 'assistant'])],
            'attendees.*.is_organizer' => ['nullable', 'boolean'],
            'attendees.*.status' => ['nullable', 'string', Rule::in(['accepted', 'pending', 'declined'])],
        ];
    }
}
