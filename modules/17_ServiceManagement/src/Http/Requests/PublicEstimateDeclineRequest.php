<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

final class PublicEstimateDeclineRequest extends WarrantyRmaFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'declined_by_name' => ['required', 'string', 'max:150'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
