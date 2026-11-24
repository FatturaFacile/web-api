<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Models\ProcessingActivity;

final class SaveProcessingActivityRequest extends FormRequest
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
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $list = static fn (string $presence): array => [$presence, 'array', 'min:1', 'max:50'];

        return [
            'name' => [$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'data_subjects' => $list($required),
            'data_subjects.*' => ['string', 'max:255'],
            'data_categories' => $list($required),
            'data_categories.*' => ['string', 'max:255'],
            'special_categories' => ['sometimes', 'boolean'],
            'purposes' => $list($required),
            'purposes.*' => ['string', 'max:500'],
            'legal_bases' => $list($required),
            'legal_bases.*' => ['string', Rule::in(ProcessingActivity::LegalBases)],
            'collection_methods' => ['sometimes', 'nullable', 'array', 'max:20'],
            'collection_methods.*' => ['string', 'max:100'],
            'storage' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'retention' => [$required, 'string', 'max:500'],
            'recipients' => ['sometimes', 'nullable', 'array', 'max:50'],
            'recipients.*' => ['string', 'max:255'],
            'transfers_outside_eu' => ['sometimes', 'nullable', 'string', 'max:500'],
            'security_measures' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'end_of_processing' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
