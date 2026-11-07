<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Models\DataSubjectRequest;
use Modules\Associations\Support\TenantRules;

final class ReceiveDataSubjectRequest extends FormRequest
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
            'kind' => ['required', Rule::in(DataSubjectRequest::Kinds)],
            'member_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_members')],
            'requester_name' => ['required', 'string', 'max:255'],
            'requester_contact' => ['sometimes', 'nullable', 'string', 'max:255'],
            'details' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'received_at' => ['sometimes', 'date', 'before_or_equal:now'],
        ];
    }
}
