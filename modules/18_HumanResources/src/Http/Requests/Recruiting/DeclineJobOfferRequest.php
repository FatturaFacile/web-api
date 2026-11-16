<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests\Recruiting;

use Illuminate\Foundation\Http\FormRequest;

final class DeclineJobOfferRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
