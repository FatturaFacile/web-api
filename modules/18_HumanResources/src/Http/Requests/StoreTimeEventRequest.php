<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Services\TimeTracking\TimeEventType;
use Modules\HumanResources\Services\TimeTracking\TimeRecordSource;

final class StoreTimeEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Without `employee_id` and `occurred_at` it is the user clocking now; with one of them it is a manager entering a
     * record, which needs a reason.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'uuid'],
            'event_type' => ['required', Rule::enum(TimeEventType::class)],
            'source' => ['nullable', Rule::enum(TimeRecordSource::class)],
            'occurred_at' => ['bail', 'nullable', 'date', CorrectTimeEventRequest::notInTheFuture()],
            'note' => ['nullable', 'string', 'max:1000'],
            'reason' => [
                Rule::requiredIf(fn (): bool => $this->filled('occurred_at')
                    || ($this->filled('employee_id') && $this->input('employee_id') !== (string) $this->user()?->id)),
                'nullable',
                'string',
                'min:3',
                'max:1000',
            ],
        ];
    }
}
