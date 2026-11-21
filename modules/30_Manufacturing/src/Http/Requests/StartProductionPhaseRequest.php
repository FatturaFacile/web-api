<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Manufacturing\Enums\MachineMode;

/**
 * Validation of the start of machining: attended (the default) or unattended.
 */
final class StartProductionPhaseRequest extends MesCommandRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'machine_mode' => ['nullable', Rule::enum(MachineMode::class)],
        ];
    }
}
