<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Requests;

final class UploadPhotosRequest extends GreenCareRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:before,after'],
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp,heic', 'max:'.(int) config('green_care.max_photo_kb', 10240)],
        ];
    }
}
