<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Facility\Http\Requests\Concerns\FacilityRules;

/**
 * The answers of an inspection checklist: pass, fail or na for a pass/fail item, a value for a number or text item.
 */
final class CompleteInspectionRequest extends FormRequest
{
    use FacilityRules;

    /**
     * Numbers arrive as JSON numbers; the checklist stores them as text.
     */
    protected function prepareForValidation(): void
    {
        $answers = $this->input('answers');

        if (is_array($answers)) {
            foreach ($answers as $index => $answer) {
                if (is_array($answer) && isset($answer['value']) && is_scalar($answer['value'])) {
                    $answers[$index]['value'] = (string) $answer['value'];
                }
            }

            $this->merge(['answers' => $answers]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'answers' => ['required', 'array', 'min:1', 'max:100'],
            'answers.*.key' => ['required', 'string', 'max:60'],
            'answers.*.result' => ['nullable', Rule::in(['pass', 'fail', 'na'])],
            'answers.*.value' => ['nullable', 'string', 'max:500'],
            'answers.*.notes' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
