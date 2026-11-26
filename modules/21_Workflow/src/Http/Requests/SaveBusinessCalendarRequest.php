<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A business calendar: working days, working hours and time zone the SLA clocks run on.
 */
final class SaveBusinessCalendarRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:150'],
            'timezone' => ['nullable', 'timezone:all'],
            'work_monday' => ['nullable', 'boolean'],
            'work_tuesday' => ['nullable', 'boolean'],
            'work_wednesday' => ['nullable', 'boolean'],
            'work_thursday' => ['nullable', 'boolean'],
            'work_friday' => ['nullable', 'boolean'],
            'work_saturday' => ['nullable', 'boolean'],
            'work_sunday' => ['nullable', 'boolean'],
            'work_hours_start' => ['nullable', 'date_format:H:i'],
            'work_hours_end' => ['nullable', 'date_format:H:i', 'after:work_hours_start'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
