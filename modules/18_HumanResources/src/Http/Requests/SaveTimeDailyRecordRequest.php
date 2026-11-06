<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeRules;

final class SaveTimeDailyRecordRequest extends FormRequest
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
            'employee_id' => ['nullable', 'uuid'],
            'date' => ['bail', 'required', 'date_format:Y-m-d', static function (string $attribute, mixed $value, Closure $fail): void {
                if ((string) $value > CarbonImmutable::now(SwissWorkingTimeRules::TIMEZONE)->format('Y-m-d')) {
                    $fail('Non si possono registrare giorni futuri.');
                }
            }],
            'worked_minutes' => ['required', 'integer', 'between:0,1440'],
            'start_time' => ['nullable', 'date'],
            'end_time' => ['bail', 'nullable', 'date', function (string $attribute, mixed $value, Closure $fail): void {
                $start = $this->input('start_time');

                if (is_string($start) && strtotime($start) !== false
                    && SwissWorkingTimeRules::parseLocal((string) $value) <= SwissWorkingTimeRules::parseLocal($start)) {
                    $fail("La fine deve seguire l'inizio.");
                }
            }],
            'note' => ['nullable', 'string', 'max:1000'],
            'reason' => ['nullable', 'string', 'min:3', 'max:1000'],
        ];
    }
}
