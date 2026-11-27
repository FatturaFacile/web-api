<?php

declare(strict_types=1);

namespace Modules\Esterometro\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class BatchSendSelfInvoiceRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['required', 'uuid'],
        ];
    }
}
