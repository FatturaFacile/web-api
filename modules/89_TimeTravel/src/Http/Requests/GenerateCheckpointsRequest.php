<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\TimeTravel\Domain\Enums\AggregateType;

final class GenerateCheckpointsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['aggregate_type' => ['nullable', 'string', Rule::in(AggregateType::values())]];
    }

    public function aggregateType(): ?AggregateType
    {
        $type = $this->validated('aggregate_type');

        return is_string($type) && $type !== '' ? AggregateType::from($type) : null;
    }
}
