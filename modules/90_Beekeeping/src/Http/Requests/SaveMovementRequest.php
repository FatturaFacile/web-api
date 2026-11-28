<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Beekeeping\Domain\Enums\BotanicalOrigin;
use Modules\Beekeeping\Domain\Enums\MovementKind;
use Modules\Beekeeping\Domain\Enums\MovementSubject;

final class SaveMovementRequest extends BeekeepingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(MovementKind::class)],
            'origin_apiary_id' => ['required', 'uuid', $this->existsInTenant('bee_apiaries')],
            'destination_apiary_id' => ['nullable', 'uuid', $this->existsInTenant('bee_apiaries')],
            'destination_label' => ['nullable', 'string', 'max:200'],
            'destination_registry_code' => ['nullable', 'string', 'max:60'],
            'counterpart' => ['nullable', 'string', 'max:200'],
            'target_flora' => ['nullable', Rule::enum(BotanicalOrigin::class)],
            'subject' => ['sometimes', Rule::enum(MovementSubject::class)],
            'quantity' => ['required', 'integer', 'between:1,2000'],
            'vehicle_plate' => ['nullable', 'string', 'max:20'],
            'departure_date' => ['required', 'date'],
            'return_date' => ['nullable', 'date', 'after_or_equal:departure_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
