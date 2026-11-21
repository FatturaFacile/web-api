<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class FinishPasskeyRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'ceremony_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string'],
            'credential.response' => ['required', 'array'],
        ];
    }
}
