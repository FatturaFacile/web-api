<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Generates the public holidays of a year for a country (and canton or patron saint) into a calendar.
 */
final class SeedCalendarHolidaysRequest extends FormRequest
{
    /**
     * Authorisation is the `workflow_design` module key of the route group.
     */
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
            'country' => ['required', 'string', Rule::in(['CH', 'IT', 'ch', 'it'])],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'canton' => ['nullable', 'string', 'size:2', 'alpha'],
            'patron_saint' => ['nullable', 'string', 'max:20'],
        ];
    }
}
