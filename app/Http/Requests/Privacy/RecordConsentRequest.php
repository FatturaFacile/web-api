<?php

declare(strict_types=1);

namespace App\Http\Requests\Privacy;

use App\Enums\Privacy\ConsentProofChannel;
use App\Enums\Privacy\DsarSubjectType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'subject_type' => ['required', Rule::enum(DsarSubjectType::class)],
            'subject_entity_id' => ['nullable', 'string', 'max:64'],
            'subject_identifier' => ['required', 'string', 'max:255'],
            'purpose_code' => ['required', 'string', 'max:60', 'regex:/^[A-Z0-9_]+$/'],
            'consent_granted' => ['required', 'boolean'],
            'policy_version' => ['required', 'string', 'max:40'],
            'proof_channel' => ['required', Rule::enum(ConsentProofChannel::class)],
            'captured_at' => ['nullable', 'date'],
            'ip_address' => ['nullable', 'ip'],
            'user_agent' => ['nullable', 'string', 'max:500'],
        ];
    }
}
