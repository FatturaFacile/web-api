<?php

declare(strict_types=1);

namespace Modules\Events\Http\Requests;

/**
 * A session of the programme of an event.
 */
final class SessionRequest extends EventsFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'title' => ['required', 'string', 'max:160'],
            'speaker' => ['nullable', 'string', 'max:160'],
            'room' => ['nullable', 'string', 'max:80'],
            'starts_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'ends_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $rules = $this->isMethod('POST') ? [...$rules, 'ends_at' => ['required', 'date_format:Y-m-d H:i:s', 'after_or_equal:starts_at']] : $rules;

        return $this->isMethod('POST') ? ['event_id' => ['required', 'uuid', $this->ownRow('evt_events')], ...$rules] : $rules;
    }
}
