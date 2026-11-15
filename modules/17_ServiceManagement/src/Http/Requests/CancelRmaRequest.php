<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

final class CancelRmaRequest extends WarrantyRmaFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
