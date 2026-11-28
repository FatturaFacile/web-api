<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Beekeeping\Domain\Enums\VarroaCountType;

final class SaveVarroaCountRequest extends BeekeepingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hive_id' => ['required', 'uuid', $this->existsInTenant('bee_hives')],
            'treatment_id' => ['nullable', 'uuid', $this->existsInTenant('bee_varroa_treatments')],
            'counted_on' => ['required', 'date', 'before_or_equal:today'],
            'count_type' => ['required', Rule::enum(VarroaCountType::class)],
            'mites_per_day' => ['required', 'numeric', 'between:0,10000'],
        ];
    }
}
