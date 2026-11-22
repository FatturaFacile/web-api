<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * A batch of outbox messages of the MES Core relay. Only the envelope is validated here; every message is validated and
 * answered on its own, so one bad message does not fail the batch.
 */
class MesEventsRequest extends MesApiRequest
{
    /**
     * Validation rules.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:100'],
        ];
    }

    /**
     * The messages of the batch, as sent.
     *
     * @return list<mixed>
     */
    public function events(): array
    {
        return array_values((array) $this->input('events'));
    }
}
