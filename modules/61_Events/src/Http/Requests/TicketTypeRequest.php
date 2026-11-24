<?php

declare(strict_types=1);

namespace Modules\Events\Http\Requests;

/**
 * A ticket type: price and the number of places it can sell (unlimited when left out).
 */
final class TicketTypeRequest extends EventsFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'name' => ['required', 'string', 'max:120'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return $this->isMethod('POST') ? ['event_id' => ['required', 'uuid', $this->ownRow('evt_events')], ...$rules] : $rules;
    }
}
