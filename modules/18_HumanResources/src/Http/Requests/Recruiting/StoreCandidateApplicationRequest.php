<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests\Recruiting;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Enums\ApplicationSource;
use Modules\HumanResources\Enums\RecruitingLocale;

/**
 * Manual intake of a candidate by HR (`multipart/form-data`).
 */
final class StoreCandidateApplicationRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'job_opening_id' => ['required', 'uuid', Rule::exists('rec_job_openings', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:180'],
            'phone' => ['nullable', 'string', 'max:50'],
            'cover_letter' => ['nullable', 'string', 'max:20000'],
            'locale' => ['nullable', 'string', Rule::in(RecruitingLocale::values())],
            'source' => ['nullable', 'string', Rule::in(ApplicationSource::values())],
            'retention_months' => ['nullable', 'integer', Rule::in(config('recruiting.retention.allowed_months', [6, 12]))],
            'cv' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:'.(int) config('recruiting.cv_max_kilobytes', 5120)],
        ];
    }
}
