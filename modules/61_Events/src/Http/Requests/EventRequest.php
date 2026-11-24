<?php

declare(strict_types=1);

namespace Modules\Events\Http\Requests;

/**
 * An event: code, schedule, venue, capacity and waiting list. The status moves through the action endpoints.
 */
final class EventRequest extends EventsFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'venue' => ['nullable', 'string', 'max:200'],
            'starts_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'ends_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'waitlist_enabled' => ['sometimes', 'boolean'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->isMethod('POST') ? [...$rules, 'ends_at' => ['required', 'date_format:Y-m-d H:i:s', 'after_or_equal:starts_at']] : $rules;
    }
}
