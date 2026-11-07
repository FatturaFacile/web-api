<?php

declare(strict_types=1);

namespace App\Http\Requests\Translations;

use App\Http\Requests\Concerns\TargetsGlobalOrActiveTenant;
use Illuminate\Foundation\Http\FormRequest;

class StoreTranslationRequest extends FormRequest
{
    use TargetsGlobalOrActiveTenant;

    /**
     * A translation always belongs to the active tenant; only a platform administrator writes the global dictionary.
     */
    public function authorize(): bool
    {
        return $this->mayTargetRequestedScope();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tenant_id' => ['nullable', 'string'],
            'locale' => ['required', 'string', 'max:5'],
            'key' => ['required', 'string', 'max:255'],
            'value' => ['required', 'string'],
        ];
    }
}
