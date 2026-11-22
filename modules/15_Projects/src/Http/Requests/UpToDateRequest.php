<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A cut-off date for the time and materials of a project: hours and costs up to it are considered (Mod 081).
 */
final class UpToDateRequest extends FormRequest
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
            'up_to_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
