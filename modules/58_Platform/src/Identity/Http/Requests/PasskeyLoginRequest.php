<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Http\Requests;

use App\Services\Auth\TokenMode;
use Illuminate\Foundation\Http\FormRequest;

final class PasskeyLoginRequest extends FormRequest
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
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string'],
            'credential.response' => ['required', 'array'],
            'tenant_id' => ['nullable', 'uuid'],
            TokenMode::PARAMETER => ['sometimes', 'boolean'],
        ];
    }
}
