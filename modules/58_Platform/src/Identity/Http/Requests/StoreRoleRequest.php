<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Create (POST) or update (PUT) a role: name, description and permission keys. */
final class StoreRoleRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => [$this->isMethod('POST') ? 'present' : 'sometimes', 'array'],
            'permissions.*' => ['string', 'max:120'],
        ];
    }
}
