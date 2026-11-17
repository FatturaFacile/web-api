<?php

declare(strict_types=1);

namespace App\Http\Requests\SwissQrBill;

use Illuminate\Foundation\Http\FormRequest;

class ParseQrBillRequest extends FormRequest
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
            'file' => ['required_without:payload', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:10240'],
            'payload' => ['required_without:file', 'string', 'max:2000'],
        ];
    }
}
