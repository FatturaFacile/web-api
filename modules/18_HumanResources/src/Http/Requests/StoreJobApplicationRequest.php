<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreJobApplicationRequest extends FormRequest
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
            'job_posting_id' => ['required', 'uuid', TenantScopedRules::exists('hr_job_postings')],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:180'],
            'phone' => ['nullable', 'string', 'max:50'],
            'cover_note' => ['nullable', 'string', 'max:20000'],
            'source' => ['nullable', 'string', 'max:60'],
            'consent_until' => ['nullable', 'date_format:Y-m-d', 'after:today'],
        ];
    }
}
