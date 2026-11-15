<?php

declare(strict_types=1);

namespace Modules\Events\Http\Requests;

/**
 * Check-in by the QR token of the registration, optionally limited to one event.
 */
final class CheckInRequest extends EventsFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'qr_token' => ['required', 'string', 'max:64'],
            'event_id' => ['nullable', 'uuid'],
        ];
    }
}
