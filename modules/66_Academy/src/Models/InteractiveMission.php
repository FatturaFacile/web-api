<?php

declare(strict_types=1);

namespace Modules\Academy\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Academy\Domain\Enums\AcademyLocale;
use Modules\Academy\Domain\Enums\DifficultyLevel;
use Modules\Academy\Domain\ValueObjects\GuidedTourStep;
use Modules\Academy\Domain\ValueObjects\VerificationRuleSpec;

/**
 * A hands-on mission of a track, completed by doing something in a sandbox tenant.
 *
 * @property string $track_id
 * @property string $code
 * @property DifficultyLevel $difficulty
 * @property int $sequence
 * @property int $xp_reward
 * @property string|null $badge_code
 * @property array<string, mixed> $verification_rule_json
 * @property array<int, array<string, mixed>>|null $tour_steps_json
 * @property array<string, array<string, string>> $translations_json
 */
final class InteractiveMission extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'academy_interactive_missions';

    protected function casts(): array
    {
        return [
            'difficulty' => DifficultyLevel::class,
            'verification_rule_json' => 'array',
            'tour_steps_json' => 'array',
            'translations_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AcademyLearningTrack, $this>
     */
    public function track(): BelongsTo
    {
        return $this->belongsTo(AcademyLearningTrack::class, 'track_id');
    }

    public function rule(): VerificationRuleSpec
    {
        return VerificationRuleSpec::fromArray($this->verification_rule_json);
    }

    public function requiresSandbox(): bool
    {
        return $this->rule()->sandboxOnly;
    }

    /**
     * @return list<GuidedTourStep>
     */
    public function tourSteps(): array
    {
        return array_values(array_map(
            static fn (array $step): GuidedTourStep => GuidedTourStep::fromArray($step),
            $this->tour_steps_json ?? [],
        ));
    }

    public function text(string $field, AcademyLocale $locale): string
    {
        return AcademyTexts::pick($this->translations_json, $field, $locale);
    }
}
