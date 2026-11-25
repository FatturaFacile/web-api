<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * Seeded wording of the dossier: narrative sections, minutes, legal notes, validation messages, rounding labels.
 * `content` holds {locale: {title, body}}; placeholders are written {{name}}.
 *
 * @property string $template_key
 * @property string $kind
 * @property string $family
 * @property int $sort_order
 * @property array<string, array{title?: string, body?: string}> $content
 * @property list<string>|null $placeholders
 */
final class TextTemplate extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'sfs_text_templates';

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'content' => 'array',
            'placeholders' => 'array',
        ];
    }

    public function title(string $locale): string
    {
        return (string) ($this->content[$locale]['title'] ?? $this->content['en']['title'] ?? $this->template_key);
    }

    public function body(string $locale): string
    {
        return (string) ($this->content[$locale]['body'] ?? $this->content['en']['body'] ?? '');
    }
}
