<?php

declare(strict_types=1);

namespace App\Http\Requests\Privacy;

use App\Enums\Privacy\DsarRequestType;
use App\Enums\Privacy\DsarSubjectType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateDsarRequest extends FormRequest
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
            'subject_email' => ['required', 'email', 'max:255'],
            'subject_full_name' => ['required', 'string', 'max:255'],
            'request_type' => ['required', Rule::enum(DsarRequestType::class)],
            'received_at' => ['nullable', 'date'],
            'audit_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
