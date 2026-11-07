<?php

declare(strict_types=1);

namespace Modules\Assistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AskAssistantRequest extends FormRequest
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
        return ['question' => ['required', 'string', 'max:500']];
    }
}
