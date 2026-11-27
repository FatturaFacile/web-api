<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RequestInformationRequest extends FormRequest
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
        return ['note' => ['required', 'string', 'max:2000']];
    }
}
