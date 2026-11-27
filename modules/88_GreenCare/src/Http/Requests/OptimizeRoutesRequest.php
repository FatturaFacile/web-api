<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Requests;

final class OptimizeRoutesRequest extends GreenCareRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'week_start' => ['required', 'date_format:Y-m-d'],
            'crew_team_ids' => ['nullable', 'array', 'max:50'],
            'crew_team_ids.*' => ['uuid', $this->inTenant('crew_teams')],
            'refresh_weather' => ['sometimes', 'boolean'],
        ];
    }
}
