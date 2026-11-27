<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Requests;

final class RescheduleWeatherRequest extends GreenCareRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
