<?php

declare(strict_types=1);

namespace Modules\DataMigration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\DataMigration\Domain\Enums\MigrationSourceSystem;
use Modules\DataMigration\Domain\Enums\MigrationTargetEntity;

final class UploadMigrationArchiveRequest extends FormRequest
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
            'source_system' => ['required', 'string', Rule::in(MigrationSourceSystem::values())],
            'target_entities' => ['required', 'array', 'min:1'],
            'target_entities.*' => ['required', 'string', Rule::in(MigrationTargetEntity::values())],
            'legal_entity_id' => ['nullable', 'uuid'],
            'template_id' => ['nullable', 'uuid'],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xml,zip', 'max:51200'],
        ];
    }
}
