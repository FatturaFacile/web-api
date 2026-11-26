<?php

declare(strict_types=1);

namespace Modules\DataMigration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\DataMigration\Domain\Enums\MigrationTargetEntity;

final class DownloadTemplateRequest extends FormRequest
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
            'entity_type' => ['required', 'string', Rule::in(MigrationTargetEntity::values())],
            'format' => ['nullable', 'string', Rule::in(['xlsx', 'csv'])],
            'locale' => ['nullable', 'string', 'max:10'],
        ];
    }
}
