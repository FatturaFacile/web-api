<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Selection of the register operations to transmit or export.
 */
final class TransmitSianRequest extends FormRequest
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
            'movement_ids' => ['nullable', 'array', 'max:500'],
            'movement_ids.*' => ['uuid'],
            'date_to' => ['nullable', 'date'],
        ];
    }
}
