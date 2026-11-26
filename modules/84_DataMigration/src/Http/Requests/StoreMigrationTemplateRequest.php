<?php

declare(strict_types=1);

namespace Modules\DataMigration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\DataMigration\Domain\Enums\MigrationSourceSystem;
use Modules\DataMigration\Domain\Enums\MigrationTargetEntity;

final class StoreMigrationTemplateRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:160'],
            'source_system' => ['required', 'string', Rule::in(MigrationSourceSystem::values())],
            'entity_type' => ['required', 'string', Rule::in(MigrationTargetEntity::values())],
            'column_mappings' => ['required', 'array', 'min:1'],
            'value_transformations' => ['nullable', 'array'],
        ];
    }
}
