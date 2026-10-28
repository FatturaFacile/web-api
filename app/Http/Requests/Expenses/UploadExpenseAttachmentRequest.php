<?php

declare(strict_types=1);

namespace App\Http\Requests\Expenses;

use Illuminate\Foundation\Http\FormRequest;

class UploadExpenseAttachmentRequest extends FormRequest
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
            'file' => ['required_without:files', 'file', 'mimes:jpeg,png,jpg,webp,heic,pdf', 'max:20480'],
            'files' => ['required_without:file', 'array', 'max:10'],
            'files.*' => ['file', 'mimes:jpeg,png,jpg,webp,heic,pdf', 'max:20480'],
            'is_receipt' => ['nullable', 'boolean'],
        ];
    }
}
