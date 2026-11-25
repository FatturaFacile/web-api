<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Modules\CustomerSatisfaction\Domain\Enums\DispatchStatus;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyChannel;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyTriggerEvent;
use Modules\CustomerSatisfaction\Domain\Enums\ThrottleReason;

/**
 * One survey invitation: scheduled, sent (with its signed one-click token), answered, throttled by the fatigue rules or failed.
 *
 * @property string $survey_template_id
 * @property string $customer_id
 * @property string|null $contact_id
 * @property SurveyTriggerEvent $trigger_event
 * @property string|null $source_entity_type
 * @property string|null $source_entity_id
 * @property SurveyChannel $channel
 * @property string $locale
 * @property string $dispatch_token
 * @property DispatchStatus $status
 * @property ThrottleReason|null $throttle_reason
 * @property Carbon|null $scheduled_for
 * @property Carbon|null $sent_at
 * @property Carbon|null $responded_at
 * @property array<string, mixed>|null $metadata
 */
final class SurveyDispatch extends BaseEnterpriseModel
{
    protected $table = 'survey_dispatches';

    protected function casts(): array
    {
        return [
            'trigger_event' => SurveyTriggerEvent::class,
            'channel' => SurveyChannel::class,
            'status' => DispatchStatus::class,
            'throttle_reason' => ThrottleReason::class,
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SurveyTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(SurveyTemplate::class, 'survey_template_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    /**
     * @return HasOne<SurveyResponse, $this>
     */
    public function response(): HasOne
    {
        return $this->hasOne(SurveyResponse::class, 'survey_dispatch_id');
    }
}
