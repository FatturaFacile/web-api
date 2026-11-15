<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests\Recruiting;

use Illuminate\Foundation\Http\FormRequest;

final class CloseJobOpeningRequest extends FormRequest
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
            'outcome' => ['required', 'string', 'in:filled,cancelled'],
        ];
    }
}
