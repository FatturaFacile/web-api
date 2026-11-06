<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class TimeReportRequest extends FormRequest
{
    private const MAXIMUM_PERIOD_DAYS = 366;

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
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:from', function (string $attribute, mixed $value, Closure $fail): void {
                $from = CarbonImmutable::createFromFormat('Y-m-d', (string) $this->input('from'));

                if ($from !== null && $from->diffInDays(CarbonImmutable::createFromFormat('Y-m-d', (string) $value), true) >= self::MAXIMUM_PERIOD_DAYS) {
                    $fail('Il periodo non può superare un anno.');
                }
            }],
        ];
    }
}
