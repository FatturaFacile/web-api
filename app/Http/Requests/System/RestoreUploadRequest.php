<?php

declare(strict_types=1);

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload of an external backup archive (ZIP) to restore into the current tenant.
 */
class RestoreUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Guard against excessive request content lengths before processing.
     */
    protected function prepareForValidation(): void
    {
        $contentLength = (int) $this->header('Content-Length', 0);
        if ($contentLength > 20 * 1024 * 1024) {
            abort(413, 'Payload Too Large');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:20480', 'extensions:zip'],
        ];
    }
}
