<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Beekeeping\Domain\Enums\FeedingType;

/**
 * One inspection of a hive (route id) or, with `hive_ids`, the same observations on several hives of an apiary.
 */
final class RecordInspectionRequest extends BeekeepingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'apiary_id' => ['sometimes', 'uuid', $this->existsInTenant('bee_apiaries')],
            'hive_ids' => ['sometimes', 'array', 'min:1', 'max:200'],
            'hive_ids.*' => ['uuid', $this->existsInTenant('bee_hives')],
            'inspected_at' => ['nullable', 'date', 'before_or_equal:now'],
            'weather' => ['nullable', 'string', 'max:30'],
            'temperature_c' => ['nullable', 'numeric', 'between:-30,50'],
            'queen_seen' => ['sometimes', 'boolean'],
            'eggs_present' => ['sometimes', 'boolean'],
            'larvae_present' => ['sometimes', 'boolean'],
            'capped_brood_present' => ['sometimes', 'boolean'],
            'brood_frames' => ['sometimes', 'integer', 'between:0,40'],
            'store_frames' => ['sometimes', 'integer', 'between:0,40'],
            'supers_added' => ['sometimes', 'integer', 'between:0,12'],
            'supers_removed' => ['sometimes', 'integer', 'between:0,12'],
            'swarm_cells_found' => ['sometimes', 'integer', 'between:0,60'],
            'swarm_cells_destroyed' => ['sometimes', 'integer', 'between:0,60', 'lte:swarm_cells_found'],
            'feeding_type' => ['nullable', Rule::enum(FeedingType::class)],
            'feeding_kg' => ['nullable', 'numeric', 'between:0,50', 'required_with:feeding_type'],
            'strength_score' => ['nullable', 'integer', 'between:1,5'],
            'observations' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
