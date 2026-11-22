<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Requests;

/**
 * Validation of the start of a step-down preview, of a reversal reason and of the derivation test payload.
 */
final class RunRequest extends AnalyticalFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->routeIs('*.runs.preview')) {
            return [
                'schedule_id' => ['required', 'uuid', $this->owned('apportionment_schedules')],
                'period_key' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            ];
        }

        return ['reason' => ['nullable', 'string', 'max:255']];
    }
}
