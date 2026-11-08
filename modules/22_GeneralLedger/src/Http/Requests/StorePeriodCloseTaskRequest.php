<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A task of the close checklist of a period.
 */
final class StorePeriodCloseTaskRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_blocking' => ['nullable', 'boolean'],
        ];
    }
}
