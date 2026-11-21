<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Manufacturing\Enums\SuspensionReason;

/**
 * Validation of a suspension: the reason code is mandatory.
 */
final class SuspendPhaseRequest extends MesCommandRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'reason' => ['required', Rule::enum(SuspensionReason::class)],
        ];
    }
}
