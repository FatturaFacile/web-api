<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Enums\MajorityRule;
use Modules\Associations\Support\TenantRules;

final class StoreBallotRequest extends FormRequest
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
            'question' => ['required', 'string', 'max:500'],
            'options' => ['sometimes', 'array', 'min:2', 'max:20'],
            'options.*' => ['string', 'max:100', 'distinct'],
            'is_secret' => ['sometimes', 'boolean'],
            'majority_rule' => ['sometimes', Rule::enum(MajorityRule::class)],
            'qualified_percent' => ['required_if:majority_rule,qualified', 'nullable', 'numeric', 'gt:0', 'max:100'],
            'agenda_item_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_meeting_agenda_items')],
        ];
    }
}
