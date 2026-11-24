<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services\Journey;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\CRM\Models\Activity;
use Modules\MarketingAutomation\Domain\Enums\CampaignChannel;
use Modules\MarketingAutomation\Domain\Enums\CampaignStatus;
use Modules\MarketingAutomation\Domain\Enums\JourneyStepType;
use Modules\MarketingAutomation\Models\Campaign;
use Modules\MarketingAutomation\Models\Enrollment;
use Modules\MarketingAutomation\Models\JourneyStep;
use Modules\MarketingAutomation\Models\StepLog;
use Modules\MarketingAutomation\Services\MarketingMessageService;

/**
 * Executes automation journeys: enrols the audience of the trigger, then walks every enrolment through its steps
 * (send, wait, condition, CRM task) whenever it is due.
 */
final readonly class JourneyRunnerService
{
    private const int MAX_STEPS_PER_RUN = 25;

    public function __construct(
        private JourneyAudienceResolver $audience,
        private MarketingMessageService $messages,
    ) {}

    /**
     * @return int enrolments created
     */
    public function enroll(Campaign $campaign, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $steps = $this->steps($campaign);
        $entry = $steps->firstWhere('is_entry', true) ?? $steps->first();
        if ($entry === null) {
            return 0;
        }

        $created = 0;
        foreach ($this->audience->resolve($campaign, $now) as $contactId) {
            Enrollment::withoutGlobalScopes()->create([
                'tenant_id' => $campaign->tenant_id,
                'campaign_id' => $campaign->id,
                'contact_id' => $contactId,
                'current_step_key' => $entry->step_key,
                'variant' => $this->pickVariant($campaign, $contactId),
                'status' => 'active',
                'next_run_at' => $now,
            ]);
            $created++;
        }

        if ($campaign->started_at === null) {
            $campaign->forceFill(['started_at' => $now])->save();
        }

        return $created;
    }

    /**
     * Runs every due enrolment of one campaign.
     *
     * @return int steps executed
     */
    public function process(Campaign $campaign, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $steps = $this->steps($campaign)->keyBy('step_key');
        $executed = 0;

        $due = Enrollment::withoutGlobalScopes()
            ->where('campaign_id', $campaign->id)->where('status', 'active')->where('next_run_at', '<=', $now)->get();

        foreach ($due as $enrollment) {
            $executed += $this->run($campaign, $enrollment, $steps->all(), $now);
        }

        $this->completeIfFinished($campaign, $now);

        return $executed;
    }

    /**
     * @param  array<string, JourneyStep>  $steps
     */
    private function run(Campaign $campaign, Enrollment $enrollment, array $steps, Carbon $now): int
    {
        $executed = 0;

        while ($executed < self::MAX_STEPS_PER_RUN) {
            $step = $steps[(string) $enrollment->current_step_key] ?? null;
            if ($step === null) {
                $this->finish($enrollment);
                break;
            }

            $type = JourneyStepType::tryFrom((string) $step->type) ?? JourneyStepType::End;
            $executed++;
            $stop = false;
            $next = $step->next_step_key;

            switch ($type) {
                case JourneyStepType::SendEmail:
                case JourneyStepType::SendWhatsapp:
                case JourneyStepType::SendSms:
                    $this->send($campaign, $enrollment, $step, $type);
                    break;

                case JourneyStepType::Wait:
                    $hours = (int) ($step->config['hours'] ?? 0) + 24 * (int) ($step->config['days'] ?? 0);
                    $enrollment->forceFill(['current_step_key' => $next, 'next_run_at' => $now->copy()->addHours(max(0, $hours))])->save();
                    $this->log($enrollment, $step, 'waiting', $hours.'h');
                    $stop = $hours > 0;
                    if ($next === null) {
                        $this->finish($enrollment);
                        $stop = true;
                    }
                    break;

                case JourneyStepType::Condition:
                    $result = $this->condition($enrollment, (array) $step->config);
                    $next = $result ? $step->true_step_key : $step->false_step_key;
                    $this->log($enrollment, $step, $result ? 'true' : 'false', (string) ($step->config['type'] ?? ''));
                    break;

                case JourneyStepType::CrmTask:
                    $this->crmTask($campaign, $enrollment, $step);
                    break;

                case JourneyStepType::End:
                    $this->log($enrollment, $step, 'completed', null);
                    $next = null;
                    break;
            }

            if ($type === JourneyStepType::Wait) {
                if ($stop) {
                    break;
                }

                continue;
            }

            if ($next === null) {
                $this->finish($enrollment);
                break;
            }
            $enrollment->forceFill(['current_step_key' => $next])->save();
        }

        return $executed;
    }

    private function send(Campaign $campaign, Enrollment $enrollment, JourneyStep $step, JourneyStepType $type): void
    {
        $channel = match ($type) {
            JourneyStepType::SendWhatsapp => 'whatsapp',
            JourneyStepType::SendSms => 'sms',
            default => 'email',
        };

        $prepared = $this->messages->prepare($campaign, (string) $enrollment->contact_id, $enrollment->variant, $channel);
        if ($prepared['message'] === null) {
            $this->log($enrollment, $step, 'skipped', $prepared['reason']);

            return;
        }

        $this->messages->queue($prepared['message'], (array) $step->config);
        $this->log($enrollment, $step, 'queued', $prepared['message']->id);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function condition(Enrollment $enrollment, array $config): bool
    {
        $contactId = (string) $enrollment->contact_id;
        $since = $enrollment->created_at ?? Carbon::now()->subDays(30);

        $messages = DB::table('ma_messages')->where('campaign_id', $enrollment->campaign_id)->where('contact_id', $contactId)->whereNull('deleted_at');
        $opened = (clone $messages)->whereNotNull('first_opened_at')->exists();
        $clicked = (clone $messages)->whereNotNull('first_clicked_at')->exists();
        $ordered = DB::table('sales_orders')->where('tenant_id', $enrollment->tenant_id)->where('contact_id', $contactId)->whereNull('deleted_at')->where('created_at', '>=', $since)->exists()
            || DB::table('invoices')->where('tenant_id', $enrollment->tenant_id)->where('contact_id', $contactId)->whereNull('deleted_at')->whereNotNull('posted_at')->where('created_at', '>=', $since)->exists();

        return match ((string) ($config['type'] ?? '')) {
            'opened' => $opened,
            'not_opened' => ! $opened,
            'clicked' => $clicked,
            'ordered' => $ordered,
            'not_ordered' => ! $ordered,
            'clicked_not_ordered' => $clicked && ! $ordered,
            default => false,
        };
    }

    private function crmTask(Campaign $campaign, Enrollment $enrollment, JourneyStep $step): void
    {
        $config = (array) $step->config;
        $locale = (string) (DB::table('contacts')->where('id', $enrollment->contact_id)->value('document_language') ?? 'it');
        $subjects = (array) ($config['subject'] ?? []);
        $subject = (string) ($subjects[substr($locale, 0, 2)] ?? $subjects['it'] ?? $subjects['en'] ?? $campaign->name);

        $owner = DB::table('crm_accounts')->where('tenant_id', $enrollment->tenant_id)->where('contact_id', $enrollment->contact_id)->whereNull('deleted_at')->value('owner_user_id');

        Activity::withoutGlobalScopes()->create([
            'tenant_id' => $enrollment->tenant_id,
            'subject' => $subject,
            'type' => in_array($config['task_type'] ?? 'call', ['task', 'call', 'follow_up'], true) ? (string) ($config['task_type'] ?? 'call') : 'call',
            'status' => 'pending',
            'priority' => (string) ($config['priority'] ?? 'normal'),
            'due_date' => Carbon::now()->addDays((int) ($config['due_in_days'] ?? 2)),
            'assigned_user_id' => $owner,
            'related_type' => 'contact',
            'related_id' => $enrollment->contact_id,
            'metadata' => ['source' => 'marketing_automation', 'campaign_id' => $campaign->id],
        ]);

        $this->log($enrollment, $step, 'task_created', $owner === null ? 'unassigned' : 'assigned');
    }

    private function finish(Enrollment $enrollment): void
    {
        $enrollment->forceFill(['status' => 'completed', 'next_run_at' => null])->save();
    }

    private function completeIfFinished(Campaign $campaign, Carbon $now): void
    {
        if ($campaign->trigger_type !== 'scheduled_broadcast' || $campaign->status !== CampaignStatus::Active->value) {
            return;
        }

        $open = Enrollment::withoutGlobalScopes()->where('campaign_id', $campaign->id)->where('status', 'active')->exists();
        $any = Enrollment::withoutGlobalScopes()->where('campaign_id', $campaign->id)->exists();
        if ($any && ! $open) {
            $campaign->forceFill(['status' => CampaignStatus::Completed->value, 'completed_at' => $now])->save();
        }
    }

    private function log(Enrollment $enrollment, JourneyStep $step, string $outcome, ?string $detail): void
    {
        StepLog::withoutGlobalScopes()->create([
            'tenant_id' => $enrollment->tenant_id,
            'enrollment_id' => $enrollment->id,
            'campaign_id' => $enrollment->campaign_id,
            'step_key' => $step->step_key,
            'type' => $step->type,
            'outcome' => $outcome,
            'detail' => $detail,
            'executed_at' => Carbon::now(),
        ]);
    }

    /**
     * A/B split: a stable hash of the contact picks the variant by its share.
     */
    private function pickVariant(Campaign $campaign, string $contactId): ?string
    {
        $variants = (array) ($campaign->ab_test_split_variant_json['variants'] ?? []);
        if ($variants === []) {
            return null;
        }

        $bucket = crc32($campaign->id.$contactId) % 100;
        $cumulative = 0;
        foreach ($variants as $variant) {
            $cumulative += (int) ($variant['percent'] ?? 0);
            if ($bucket < $cumulative) {
                return (string) $variant['key'];
            }
        }

        return (string) ($variants[0]['key'] ?? null);
    }

    /**
     * The explicit journey, or the single send step of a broadcast without a designed journey.
     *
     * @return Collection<int, JourneyStep>
     */
    private function steps(Campaign $campaign): Collection
    {
        $steps = JourneyStep::withoutGlobalScopes()->where('campaign_id', $campaign->id)->get();
        if ($steps->isNotEmpty()) {
            return $steps;
        }

        $type = match (CampaignChannel::tryFrom((string) $campaign->channel)) {
            CampaignChannel::WhatsAppMarketing => JourneyStepType::SendWhatsapp,
            CampaignChannel::SmsPromo => JourneyStepType::SendSms,
            default => JourneyStepType::SendEmail,
        };

        $implicit = new JourneyStep(['campaign_id' => $campaign->id, 'tenant_id' => $campaign->tenant_id, 'step_key' => 'send', 'type' => $type->value, 'is_entry' => true]);

        return collect([$implicit]);
    }
}
