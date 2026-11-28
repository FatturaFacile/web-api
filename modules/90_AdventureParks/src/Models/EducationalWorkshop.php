<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * A workshop of the educational farm. The global rows (tenant_id null) are the standard catalogue with its names and descriptions in
 * every language; a company overrides one with its own row of the same code (price, duration, capacity).
 *
 * @property string $code
 * @property int $duration_minutes
 * @property int $max_participants
 * @property string $price_per_student
 * @property array<string, string>|null $name_translations
 * @property array<string, string>|null $description_translations
 * @property bool $is_active
 */
final class EducationalWorkshop extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'adv_educational_workshops';

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'max_participants' => 'integer',
            'price_per_student' => 'decimal:2',
            'name_translations' => 'array',
            'description_translations' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function localizedName(string $locale): string
    {
        $names = $this->name_translations ?? [];

        return (string) ($names[$locale] ?? $names['en'] ?? $this->code);
    }
}
