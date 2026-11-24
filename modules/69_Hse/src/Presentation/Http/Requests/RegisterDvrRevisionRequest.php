<?php

declare(strict_types=1);

namespace Modules\Hse\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RegisterDvrRevisionRequest extends FormRequest
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
            'issued_at' => ['required', 'date'],
            'review_due_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'document_id' => ['nullable', 'uuid'],
            'signatures' => ['nullable', 'array'],
            'signatures.*.signed_by' => ['required', 'string', 'max:160'],
            'signatures.*.signed_at' => ['required', 'date'],
        ];
    }
}
