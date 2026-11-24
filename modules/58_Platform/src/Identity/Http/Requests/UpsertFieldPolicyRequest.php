<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpsertFieldPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'policies' => ['present', 'array'],
            'policies.*.resource' => ['required', 'string', 'max:80'],
            'policies.*.field' => ['required', 'string', 'max:80'],
            'policies.*.visibility' => ['required', Rule::in(['visible', 'masked', 'hidden'])],
            'policies.*.kind' => ['sometimes', Rule::in(['amount', 'iban'])],
        ];
    }
}
