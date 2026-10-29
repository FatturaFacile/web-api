<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConsumeRemoteTokenRequest extends FormRequest
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
            'token' => ['required', 'string', 'min:32'],
            'action' => ['required', 'string', 'in:approve,reject'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
