<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Transportation\Models\TmsTrackingEvent;

/**
 * Payload to create or change a tracking event.
 */
final class TrackingEventRequest extends TransportationFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'event_type' => ['required', Rule::in(TmsTrackingEvent::EventTypes)],
            'location' => ['nullable', 'string', 'max:160'],
            'occurred_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->isMethod('POST') ? [
                'transport_order_id' => ['required', 'uuid', $this->ownRow('tms_transport_orders')],
            ...$rules,
        ] : $rules;
    }
}
