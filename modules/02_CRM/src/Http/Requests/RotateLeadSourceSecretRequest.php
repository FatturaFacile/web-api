<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RotateLeadSourceSecretRequest extends FormRequest
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
            // Which credential to regenerate; both by default.
            'rotate' => ['nullable', 'string', Rule::in(['both', 'webhook_secret', 'api_key'])],
        ];
    }
}
