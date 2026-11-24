<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Revokes a declaration of intent.
 */
final class RevokeDeclarationRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:200'],
        ];
    }
}
