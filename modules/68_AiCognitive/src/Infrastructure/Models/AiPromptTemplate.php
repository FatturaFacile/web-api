<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A versioned prompt of a tenant. A tenant template overrides the built-in prompt of the same key and locale.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $key
 * @property string $locale
 * @property int $version
 * @property string $system_prompt
 * @property string|null $user_prompt
 * @property array<string, mixed>|null $output_schema_json
 * @property bool $is_active
 */
final class AiPromptTemplate extends BaseEnterpriseModel
{
    protected $table = 'ai_prompt_templates';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'output_schema_json' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
