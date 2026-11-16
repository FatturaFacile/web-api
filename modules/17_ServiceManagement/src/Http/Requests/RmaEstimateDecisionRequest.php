<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

final class RmaEstimateDecisionRequest extends WarrantyRmaFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
