<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeRules;
use Modules\HumanResources\Services\TimeTracking\TimeEventType;
use Modules\HumanResources\Services\TimeTracking\TimeRecordSource;

final class CorrectTimeEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'occurred_at' => ['bail', 'sometimes', 'required', 'date', self::notInTheFuture()],
            'event_type' => ['sometimes', 'required', Rule::enum(TimeEventType::class)],
            'source' => ['sometimes', 'required', Rule::enum(TimeRecordSource::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * A clock event cannot be in the future (a few minutes of tolerance for the clock of the device).
     */
    public static function notInTheFuture(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (SwissWorkingTimeRules::parseLocal((string) $value) > CarbonImmutable::now()->addMinutes(5)) {
                $fail('Non si possono registrare timbrature future.');
            }
        };
    }
}
