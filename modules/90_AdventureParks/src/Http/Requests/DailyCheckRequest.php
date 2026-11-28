<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\AdventureParks\Models\DailyCourseCheck;

/**
 * Signed daily opening check of the courses (UNI EN 15567-2).
 */
final class DailyCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('operate', DailyCourseCheck::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'check_date' => ['required', 'date_format:Y-m-d'],
            'checked_by_name' => ['required', 'string', 'max:150'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.key' => ['required', 'string', Rule::in((array) config('adventure_parks.daily_check_items'))],
            'items.*.ok' => ['required', 'boolean'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'signature' => ['required', 'string', 'max:'.(int) config('adventure_parks.signature_max_length', 700000)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
