<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Services\TimeTracking\TimeRecordSource;

final class StoreMealBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Without `employee_id` and `occurred_at` the user books now; with one of them it is a manager entering a booking.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'uuid'],
            'meal_type' => ['required', 'string', 'max:30'],
            'occurred_at' => ['bail', 'nullable', 'date', CorrectTimeEventRequest::notInTheFuture()],
            'source' => ['nullable', Rule::enum(TimeRecordSource::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
