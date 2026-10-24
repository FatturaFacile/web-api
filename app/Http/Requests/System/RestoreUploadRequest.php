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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:zip', 'max:'.(int) config('backup.max_upload_kb', 512000)],
        ];
    }
}
