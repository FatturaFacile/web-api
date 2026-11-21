<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of the commands that carry only the common fields: start setup, resume.
 */
final class PhaseTransitionRequest extends MesCommandRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->commandRules();
    }
}
