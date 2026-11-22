<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A manual move of a scheduled operation: new start or end date and/or an alternative work centre.
 */
final class RescheduleBlockRequest extends FormRequest
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
            'start_date' => ['nullable', 'date', 'required_without_all:end_date,work_center_id'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'work_center_id' => ['nullable', 'uuid'],
        ];
    }
}
