<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Manufacturing\Enums\DefectReason;

/**
 * Validation of a declaration of good and scrapped pieces: scrap needs its defect reason.
 */
final class DeclareProductionRequest extends MesCommandRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->commandRules(),
            'good_quantity' => ['required', ...self::Quantity],
            'scrap_quantity' => ['nullable', ...self::Quantity],
            'defect_reason' => [Rule::requiredIf(fn (): bool => (float) $this->input('scrap_quantity', 0) > 0), 'nullable', Rule::enum(DefectReason::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
