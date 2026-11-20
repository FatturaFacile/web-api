<?php

declare(strict_types=1);

namespace Modules\Academy\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * Help for one ERP screen in one language, with the tooltips of its fields (field key => text).
 *
 * @property string $route_pattern
 * @property string $locale
 * @property string $title
 * @property string $body_markdown
 * @property array<string, string>|null $field_tooltips_json
 */
final class ContextualHelpArticle extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'academy_contextual_help_articles';

    protected function casts(): array
    {
        return ['field_tooltips_json' => 'array'];
    }
}
