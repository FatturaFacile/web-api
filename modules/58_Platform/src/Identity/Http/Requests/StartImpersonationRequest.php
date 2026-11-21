<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StartImpersonationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'consent_reference' => ['required', 'string', 'max:255'],
            'ttl_minutes' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ];
    }
}
