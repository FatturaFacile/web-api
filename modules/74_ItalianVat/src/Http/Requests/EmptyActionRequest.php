<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An action that takes no data.
 */
final class EmptyActionRequest extends FormRequest
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
        return [];
    }
}
