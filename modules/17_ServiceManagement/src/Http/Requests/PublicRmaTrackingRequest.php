<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

final class PublicRmaTrackingRequest extends WarrantyRmaFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rma_number' => ['required', 'string', 'max:30'],
            'token' => ['required', 'string', 'max:128'],
        ];
    }
}
