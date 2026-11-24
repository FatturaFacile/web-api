<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Enums\CertificateKind;
use Modules\Associations\Support\TenantRules;

final class StoreCertificateRequest extends FormRequest
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
            'kind' => ['required', Rule::enum(CertificateKind::class)],
            'discipline_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_disciplines')],
            'issued_on' => ['sometimes', 'nullable', 'date'],
            'expires_on' => ['required', 'date'],
            'physician' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'file' => ['sometimes', 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.config('associations.upload_max_kilobytes')],
        ];
    }
}
