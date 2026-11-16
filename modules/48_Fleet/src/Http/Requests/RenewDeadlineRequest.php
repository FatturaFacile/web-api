<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Requests;

/**
 * The renewal of a deadline: the new expiry date and, when they change, the cost, provider and reference.
 */
final class RenewDeadlineRequest extends FleetFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expires_on' => ['required', 'date_format:Y-m-d'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'provider' => ['nullable', 'string', 'max:160'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
