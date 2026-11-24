<?php

declare(strict_types=1);

namespace Modules\Academy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateClassroomCohortRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:160'],
            'track_ids' => ['nullable', 'array', 'max:20'],
            'track_ids.*' => ['uuid', Rule::exists('academy_learning_tracks', 'id')->whereNull('deleted_at')],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
