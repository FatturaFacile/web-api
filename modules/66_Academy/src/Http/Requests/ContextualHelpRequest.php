<?php

declare(strict_types=1);

namespace Modules\Academy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Academy\Domain\Enums\AcademyLocale;

final class ContextualHelpRequest extends FormRequest
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
            'route' => ['required', 'string', 'max:255'],
            'locale' => ['nullable', 'string', Rule::in([...AcademyLocale::values(), 'it', 'de', 'fr'])],
        ];
    }
}
