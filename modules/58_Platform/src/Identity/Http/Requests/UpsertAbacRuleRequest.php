<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Platform\Identity\Domain\ValueObjects\AbacCondition;

final class UpsertAbacRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'rules' => ['present', 'array'],
            'rules.*.permission' => ['required', 'string', 'max:120'],
            'rules.*.effect' => ['required', Rule::in(['allow', 'deny'])],
            'rules.*.conditions' => ['required', 'array', 'min:1'],
            'rules.*.conditions.*.attribute' => ['required', Rule::in(AbacCondition::ATTRIBUTES)],
            'rules.*.conditions.*.operator' => ['required', Rule::in(AbacCondition::OPERATORS)],
            'rules.*.conditions.*.value' => ['present'],
        ];
    }
}
