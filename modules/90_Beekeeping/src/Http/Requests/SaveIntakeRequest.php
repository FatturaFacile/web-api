<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Beekeeping\Domain\Enums\BotanicalOrigin;

final class SaveIntakeRequest extends BeekeepingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'apiary_id' => ['required', 'uuid', $this->existsInTenant('bee_apiaries')],
            'harvested_on' => ['required', 'date', 'before_or_equal:today'],
            'botanical_origin' => ['required', Rule::enum(BotanicalOrigin::class)],
            'supers_count' => ['required', 'integer', 'between:1,500'],
            'gross_weight_kg' => ['required', 'numeric', 'min:0', 'max:99999'],
            'net_weight_kg' => ['required', 'numeric', 'min:0', 'max:99999', 'lte:gross_weight_kg'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
