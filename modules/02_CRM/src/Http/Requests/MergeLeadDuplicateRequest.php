<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\CRM\Contracts\LeadMergeServiceInterface;

final class MergeLeadDuplicateRequest extends FormRequest
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
            'survivor' => ['required', 'string', Rule::in(['lead', 'candidate'])],
            'field_choices' => ['nullable', 'array'],
            'field_choices.*' => ['string', Rule::in(['lead', 'candidate'])],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $choices = $this->input('field_choices');
                if (! is_array($choices)) {
                    return;
                }

                foreach (array_keys($choices) as $field) {
                    if (! in_array($field, LeadMergeServiceInterface::MERGEABLE_FIELDS, true)) {
                        $validator->errors()->add('field_choices.'.$field, 'This field cannot be merged.');
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, 'lead'|'candidate'>
     */
    public function fieldChoices(): array
    {
        $choices = $this->input('field_choices');

        /** @var array<string, 'lead'|'candidate'> $filtered */
        $filtered = is_array($choices) ? array_filter($choices, static fn (mixed $value): bool => $value === 'lead' || $value === 'candidate') : [];

        return $filtered;
    }
}
