<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SkiOutdoorSchool\Domain\Enums\Discipline;

/**
 * Week and filters of the level reassignment board.
 */
final class LevelBoardRequest extends FormRequest
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
            'week_start' => ['required', 'date'],
            'discipline' => ['nullable', Rule::in(Discipline::values())],
            'language' => ['nullable', 'string', 'regex:/^[A-Za-z]{2,3}$/'],
        ];
    }
}
