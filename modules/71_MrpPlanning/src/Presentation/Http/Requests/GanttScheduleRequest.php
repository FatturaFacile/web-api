<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The window and work centres of the Gantt view.
 */
final class GanttScheduleRequest extends FormRequest
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
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'work_center_ids' => ['nullable', 'array', 'max:100'],
            'work_center_ids.*' => ['uuid'],
        ];
    }
}
