<?php

declare(strict_types=1);

namespace Modules\Intrastat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Records that a validated list was sent, with the protocol the receiving channel assigned to it.
 */
final class SubmitDeclarationRequest extends FormRequest
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
            'protocol_number' => ['required', 'string', 'max:30'],
            'submitted_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }
}
