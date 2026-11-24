<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Multipart upload of a photo of a field work order from the technician app; `client_uuid` makes it idempotent.
 */
final class StoreWorkOrderPhotoRequest extends FormRequest
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
            'photo' => ['required', 'file', 'mimes:jpeg,jpg,png,heic,heif,webp', 'max:10240'],
            'client_uuid' => ['required', 'string', 'max:64'],
            'stage' => ['nullable', 'in:before,during,after'],
            'caption' => ['nullable', 'string', 'max:255'],
            'captured_at' => ['nullable', 'date'],
        ];
    }
}
