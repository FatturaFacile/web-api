<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Services\RegistryImportService;

final class CommitImportRequest extends FormRequest
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
        return ['on_duplicate' => ['sometimes', Rule::in([RegistryImportService::OnDuplicateSkip, RegistryImportService::OnDuplicateUpdate])]];
    }
}
