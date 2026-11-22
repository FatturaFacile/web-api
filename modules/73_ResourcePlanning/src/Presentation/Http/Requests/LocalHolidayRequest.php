<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Adds a local holiday: a municipal patron saint, a cantonal or regional feast. Without a year it recurs every year.
 */
final class LocalHolidayRequest extends FormRequest
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
            'country' => ['required', 'string', 'size:2'],
            'region_code' => ['nullable', 'string', 'max:10'],
            'municipality' => ['nullable', 'string', 'max:120'],
            'month' => ['required', 'integer', 'between:1,12'],
            'day' => ['required', 'integer', 'between:1,31'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'name' => ['required', 'string', 'max:150'],
            'day_fraction' => ['nullable', 'numeric', 'between:0.1,1'],
        ];
    }
}
