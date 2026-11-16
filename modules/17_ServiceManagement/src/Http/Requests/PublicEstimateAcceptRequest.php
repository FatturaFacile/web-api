<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

final class PublicEstimateAcceptRequest extends WarrantyRmaFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'accepted_by_name' => ['required', 'string', 'max:150'],
        ];
    }
}
