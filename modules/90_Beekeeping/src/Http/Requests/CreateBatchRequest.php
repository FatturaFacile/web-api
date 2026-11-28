<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Beekeeping\Domain\Enums\BotanicalOrigin;

final class CreateBatchRequest extends BeekeepingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'intake_ids' => ['required', 'array', 'min:1', 'max:200'],
            'intake_ids.*' => ['uuid', $this->existsInTenant('bee_super_intakes')],
            'extracted_on' => ['required', 'date', 'before_or_equal:today'],
            'botanical_origin' => ['nullable', Rule::enum(BotanicalOrigin::class)],
            'extracted_kg' => ['nullable', 'numeric', 'min:0.01', 'max:99999'],
            'hives_harvested' => ['nullable', 'integer', 'between:1,2000'],
            'tank_code' => ['nullable', 'string', 'max:40'],
            'process_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
