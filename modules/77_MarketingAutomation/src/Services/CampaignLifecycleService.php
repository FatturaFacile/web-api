<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services;

use Illuminate\Support\Carbon;
use Modules\MarketingAutomation\Domain\Enums\CampaignChannel;
use Modules\MarketingAutomation\Domain\Enums\CampaignStatus;
use Modules\MarketingAutomation\Exceptions\MarketingAutomationException;
use Modules\MarketingAutomation\Models\Campaign;
use Modules\MarketingAutomation\Services\Mjml\CampaignTemplateService;

/**
 * Status machine of a campaign: draft -> scheduled/active -> paused/completed -> archived. Activating checks that the
 * campaign can legally send (sender identity, audience, a template that compiles).
 */
final readonly class CampaignLifecycleService
{
    private const array TRANSITIONS = [
        'draft' => ['scheduled', 'active', 'archived'],
        'scheduled' => ['active', 'paused', 'draft', 'archived'],
        'active' => ['paused', 'completed', 'archived'],
        'paused' => ['active', 'completed', 'archived'],
        'completed' => ['archived'],
        'archived' => [],
    ];

    public function __construct(private CampaignTemplateService $templates) {}

    public function transition(Campaign $campaign, CampaignStatus $target): Campaign
    {
        $from = (string) $campaign->status;
        if (! in_array($target->value, self::TRANSITIONS[$from] ?? [], true)) {
            throw MarketingAutomationException::invalidTransition($from, $target->value);
        }

        if (in_array($target, [CampaignStatus::Active, CampaignStatus::Scheduled], true)) {
            $this->assertCanSend($campaign);
            if (CampaignChannel::tryFrom((string) $campaign->channel) !== CampaignChannel::SmsPromo && CampaignChannel::tryFrom((string) $campaign->channel) !== CampaignChannel::WhatsAppMarketing) {
                $this->templates->compileAndCache($campaign);
            }
        }

        $campaign->forceFill([
            'status' => $target->value,
            'started_at' => $target === CampaignStatus::Active ? ($campaign->started_at ?? Carbon::now()) : $campaign->started_at,
            'completed_at' => $target === CampaignStatus::Completed ? Carbon::now() : $campaign->completed_at,
        ])->save();

        return $campaign;
    }

    private function assertCanSend(Campaign $campaign): void
    {
        $channel = CampaignChannel::tryFrom((string) $campaign->channel);
        $usesEmail = $channel === CampaignChannel::EmailDem || $channel === CampaignChannel::MultiChannelJourney;

        if ($usesEmail && (trim((string) $campaign->sender_name) === '' || trim((string) $campaign->sender_email) === '' || trim((string) $campaign->sender_postal_address) === '')) {
            throw MarketingAutomationException::validation('sender_identity_required');
        }
        if ($campaign->trigger_type === 'scheduled_broadcast' && $campaign->segment_id === null) {
            throw MarketingAutomationException::validation('segment_required');
        }
        if ($usesEmail && empty($campaign->template_mjml_json)) {
            throw MarketingAutomationException::validation('template_required');
        }
    }
}
