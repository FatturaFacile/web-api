<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyTriggerEvent;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyType;

/**
 * A survey campaign and its trigger rule: the instrument (NPS, CSAT, CES), the ERP event that fires it, the channels, the delay,
 * the fatigue cooldown, the detractor threshold with its SLA and the localized texts.
 *
 * @property string $code
 * @property string $name
 * @property SurveyType $survey_type
 * @property SurveyTriggerEvent $trigger_event
 * @property array<string, mixed>|null $trigger_filters
 * @property int $priority
 * @property list<string> $channels
 * @property int $delay_hours
 * @property int $cooldown_days
 * @property int|null $detractor_threshold
 * @property int $sla_hours
 * @property bool $allow_voice_comment
 * @property array<string, array<string, string>>|null $messages
 * @property array<string, array<string, string>>|null $follow_up_config
 * @property array<string, string|null>|null $dimensions
 * @property bool $is_active
 */
final class SurveyTemplate extends BaseEnterpriseModel
{
    protected $table = 'survey_templates';

    protected function casts(): array
    {
        return [
            'survey_type' => SurveyType::class,
            'trigger_event' => SurveyTriggerEvent::class,
            'trigger_filters' => 'array',
            'priority' => 'integer',
            'channels' => 'array',
            'delay_hours' => 'integer',
            'cooldown_days' => 'integer',
            'detractor_threshold' => 'integer',
            'sla_hours' => 'integer',
            'allow_voice_comment' => 'boolean',
            'messages' => 'array',
            'follow_up_config' => 'array',
            'dimensions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<SurveyDispatch, $this>
     */
    public function dispatches(): HasMany
    {
        return $this->hasMany(SurveyDispatch::class, 'survey_template_id');
    }

    public function effectiveDetractorThreshold(): int
    {
        return $this->detractor_threshold ?? $this->survey_type->defaultDetractorThreshold();
    }
}
