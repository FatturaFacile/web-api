<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TransitionDossierRequest extends FormRequest
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
            'target' => ['required', Rule::in(['locked', 'filed', 'draft'])],
            'reference' => ['nullable', 'string', 'max:120'],
        ];
    }
}
