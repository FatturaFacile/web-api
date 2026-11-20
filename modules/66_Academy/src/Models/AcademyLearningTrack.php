<?php

declare(strict_types=1);

namespace Modules\Academy\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academy\Domain\Enums\AcademyLocale;
use Modules\Academy\Domain\Enums\DifficultyLevel;

/**
 * A learning track of the shared catalogue (tenant_id null). The texts are a map locale => {title, description}.
 *
 * @property string $code
 * @property DifficultyLevel $difficulty
 * @property int $sort_order
 * @property array<string, array<string, string>> $translations_json
 */
final class AcademyLearningTrack extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'academy_learning_tracks';

    protected function casts(): array
    {
        return ['difficulty' => DifficultyLevel::class, 'translations_json' => 'array'];
    }

    /**
     * @return HasMany<InteractiveMission, $this>
     */
    public function missions(): HasMany
    {
        return $this->hasMany(InteractiveMission::class, 'track_id')->orderBy('sequence');
    }

    /**
     * The title or description in the first available language of the locale fallback chain.
     */
    public function text(string $field, AcademyLocale $locale): string
    {
        return AcademyTexts::pick($this->translations_json, $field, $locale);
    }
}
