<?php

declare(strict_types=1);

namespace Modules\Events\Http\Requests;

/**
 * A registration. Event and ticket type are fixed on creation; afterwards only the attendee data changes.
 */
final class RegistrationRequest extends EventsFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'contact_id' => ['nullable', 'uuid', $this->ownRow('contacts')],
            'attendee_name' => ['required', 'string', 'max:160'],
            'attendee_email' => ['nullable', 'email', 'max:190'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->isMethod('POST')
            ? ['event_id' => ['required', 'uuid', $this->ownRow('evt_events')], 'ticket_type_id' => ['required', 'uuid', $this->ownRow('evt_ticket_types')], ...$rules]
            : $rules;
    }
}
