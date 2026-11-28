<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Beekeeping\Domain\Enums\MovementStatus;

final class MovementStatusRequest extends BeekeepingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(MovementStatus::class)],
            'arrival_date' => ['nullable', 'date'],
        ];
    }
}
