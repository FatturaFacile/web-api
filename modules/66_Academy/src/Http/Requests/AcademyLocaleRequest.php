<?php

declare(strict_types=1);

namespace Modules\Academy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Academy\Domain\Enums\AcademyLocale;

/**
 * Request with only the optional language of the answer: used by the mission and progress endpoints.
 */
final class AcademyLocaleRequest extends FormRequest
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
        return ['locale' => ['nullable', 'string', Rule::in([...AcademyLocale::values(), 'it', 'de', 'fr'])]];
    }

    public function academyLocale(): AcademyLocale
    {
        return AcademyLocale::fromInput($this->validated('locale') ?? $this->user()->locale ?? app()->getLocale());
    }
}
