<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\CRM\Support\LocalizedLabel;

/**
 * Structured reason an opportunity is lost (price, specific competitor, missing features, frozen project, ...).
 *
 * @property string $code
 * @property string $name
 * @property array<string, string>|null $translations
 * @property bool $requires_competitor
 */
final class LossReason extends BaseEnterpriseModel
{
    protected $table = 'crm_loss_reasons';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'translations' => 'array',
            'requires_competitor' => 'boolean',
            'sequence' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function label(): string
    {
        return LocalizedLabel::resolve($this->translations, (string) $this->name);
    }
}
