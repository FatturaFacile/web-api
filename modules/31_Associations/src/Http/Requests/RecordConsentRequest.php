<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Support\TenantRules;

final class RecordConsentRequest extends FormRequest
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
            'member_id' => ['required_without:contact_id', 'nullable', 'uuid', TenantRules::exists('assoc_members')],
            'contact_id' => ['required_without:member_id', 'nullable', 'uuid', TenantRules::exists('contacts')],
            'activity_id' => ['required', 'uuid', TenantRules::exists('assoc_processing_activities')],
            'purpose' => ['required', 'string', 'max:500'],
            'method' => ['required', Rule::in(['online_form', 'paper_form', 'email', 'member_area', 'other'])],
            'text_version' => ['required', 'string', 'max:100'],
            'proof' => ['required', 'string', 'max:10000'],
            'given_at' => ['sometimes', 'date', 'before_or_equal:now'],
        ];
    }
}
