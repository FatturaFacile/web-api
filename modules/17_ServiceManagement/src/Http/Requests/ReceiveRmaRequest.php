<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

final class ReceiveRmaRequest extends WarrantyRmaFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'received_at' => ['nullable', 'date'],
            'receipt_condition_notes' => ['nullable', 'string'],
            'receipt_photos' => ['nullable', 'array', 'max:20'],
            'receipt_photos.*.url' => ['required_without:receipt_photos.*.asset_id', 'nullable', 'string', 'max:2048'],
            'receipt_photos.*.asset_id' => ['required_without:receipt_photos.*.url', 'nullable', 'string', 'max:100'],
            'receipt_photos.*.caption' => ['nullable', 'string', 'max:255'],
        ];
    }
}
