<?php

declare(strict_types=1);

namespace Modules\Hospitality\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reservations of tables, the day calendar and the availability search. Each route names the payload it validates.
 */
final class ReservationRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Always allowed here: who may call the endpoint is decided by the module.access middleware and the policy check
     * of the controller.
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
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return match (str_replace('api.v1.hospitality.', '', (string) $this->route()?->getName())) {
            'reservations.store', 'reservations.update' => [
                'table_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('hosp_tables')],
                'contact_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('contacts')],
                'guest_name' => [$presence, 'string', 'max:150'],
                'guest_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
                'guest_email' => ['sometimes', 'nullable', 'email', 'max:190'],
                'party_size' => [$presence, 'integer', 'min:1', 'max:100'],
                'starts_at' => [$presence, 'date'],
                'duration_minutes' => [$presence, 'integer', 'min:15', 'max:720'],
                'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            ],
            'reservations.index' => [
                'date' => ['required', 'date_format:Y-m-d'],
                'room_id' => ['sometimes', 'uuid', $this->tenantOwns('hosp_rooms')],
                'status' => ['sometimes', 'string', 'in:booked,seated,completed,cancelled,no_show'],
            ],
            'reservations.availability' => [
                'starts_at' => ['required', 'date'],
                'duration_minutes' => ['required', 'integer', 'min:15', 'max:720'],
                'party_size' => ['required', 'integer', 'min:1', 'max:100'],
            ],
            'reservations.seat' => ['table_id' => ['sometimes', 'uuid', $this->tenantOwns('hosp_tables')]],
            'reservations.status' => [
                'status' => ['required', 'string', 'in:cancelled,no_show,completed'],
                'reason' => ['required_if:status,cancelled,no_show', 'nullable', 'string', 'min:3', 'max:255'],
            ],
            default => [],
        };
    }
}
