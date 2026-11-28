<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\TimeTravel\Support\Instant;

/**
 * Range of dates of the integrity certificate (at most three years: the verification recomputes every hash of the range).
 */
final class AuditIntegrityRequest extends FormRequest
{
    private const int MAX_DAYS = 1096;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->from()->diffInDays($this->to()) > self::MAX_DAYS) {
                    $validator->errors()->add('to_date', __('time_travel.errors.range_too_long', ['days' => self::MAX_DAYS]));
                }
            },
        ];
    }

    public function from(): CarbonImmutable
    {
        return Instant::startOfDay((string) $this->input('from_date'));
    }

    public function to(): CarbonImmutable
    {
        return Instant::startOfDay((string) $this->input('to_date'));
    }
}
