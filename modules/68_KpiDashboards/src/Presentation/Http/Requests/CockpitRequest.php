<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Presentation\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\KpiDashboards\Domain\Enums\RoleCode;

final class CockpitRequest extends CockpitFiltersRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'role' => ['nullable', Rule::enum(RoleCode::class)],
            'dashboard_id' => ['nullable', 'uuid'],
        ];
    }
}
