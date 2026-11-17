<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests\Recruiting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Enums\RecruitingLocale;
use Modules\HumanResources\Http\Middleware\ResolveCareersTenant;
use Modules\HumanResources\Models\RecruitingSettings;

/**
 * Public careers page application (`multipart/form-data`). `website` is a honeypot that humans leave empty.
 */
final class PublicApplyRequest extends FormRequest
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
        $settings = $this->attributes->get(ResolveCareersTenant::SETTINGS_ATTRIBUTE);
        $currentVersion = $settings instanceof RecruitingSettings ? (string) $settings->consent_text_version : '';

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:180'],
            'phone' => ['nullable', 'string', 'max:50'],
            'cover_letter' => ['nullable', 'string', 'max:20000'],
            'locale' => ['required', 'string', Rule::in(RecruitingLocale::values())],
            'consent_accepted' => ['required', 'accepted'],
            'consent_text_version' => ['required', 'string', Rule::in([$currentVersion])],
            'cv' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:'.(int) config('recruiting.cv_max_kilobytes', 5120)],
            'website' => ['prohibited'],
        ];
    }
}
