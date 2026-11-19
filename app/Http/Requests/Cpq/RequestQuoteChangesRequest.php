<?php

declare(strict_types=1);

namespace App\Http\Requests\Cpq;

use Illuminate\Foundation\Http\FormRequest;

class RequestQuoteChangesRequest extends FormRequest
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
            'message' => ['required', 'string', 'max:2000'],
        ];
    }
}
