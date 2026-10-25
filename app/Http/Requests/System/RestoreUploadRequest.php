<?php

declare(strict_types=1);

namespace App\Http\Requests\System;

use App\Http\Requests\Concerns\ValidatesUploadContentLength;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload of an external backup archive (ZIP) to restore into the current tenant.
 */
class RestoreUploadRequest extends FormRequest
{
    use ValidatesUploadContentLength;

    protected int $maxContentLengthBytes = 25 * 1024 * 1024;

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
            'file' => ['required', 'file', 'max:25600', 'extensions:zip'],
        ];
    }
}
