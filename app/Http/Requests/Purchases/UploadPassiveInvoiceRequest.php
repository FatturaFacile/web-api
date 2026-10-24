<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchases;

use Illuminate\Foundation\Http\FormRequest;

class UploadPassiveInvoiceRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimetypes:text/xml,application/xml,text/plain', 'max:10240'],
        ];
    }
}
