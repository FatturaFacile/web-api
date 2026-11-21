<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MrpPlanning\Domain\Enums\SchedulingDirection;

/**
 * Auto-leveling of the finite capacity of a run.
 */
final class AutoLevelCapacityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scheduling_direction' => ['sometimes', Rule::enum(SchedulingDirection::class)],
        ];
    }
}
