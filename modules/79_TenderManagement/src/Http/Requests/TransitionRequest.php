<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\TenderManagement\Domain\Enums\ParticipationStatus;

/**
 * A status change of a participation dossier.
 */
final class TransitionRequest extends FormRequest
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
            'status' => ['required', Rule::in(array_map(static fn (ParticipationStatus $status): string => $status->value, ParticipationStatus::cases()))],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
