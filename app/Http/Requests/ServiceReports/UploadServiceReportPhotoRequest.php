<?php

declare(strict_types=1);

namespace App\Http\Requests\ServiceReports;

use Illuminate\Foundation\Http\FormRequest;

class UploadServiceReportPhotoRequest extends FormRequest
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
            'photo' => ['required', 'file', 'image', 'mimes:jpeg,png,jpg,webp', 'max:10240'],
            'stage' => ['nullable', 'string', 'in:before,after,general,receipt'],
            'caption' => ['nullable', 'string', 'max:255'],
        ];
    }
}
