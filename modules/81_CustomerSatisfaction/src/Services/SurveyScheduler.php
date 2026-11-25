<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Services;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CustomerSatisfaction\Domain\Enums\DispatchStatus;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyChannel;
use Modules\CustomerSatisfaction\Domain\Enums\ThrottleReason;
use Modules\CustomerSatisfaction\Domain\Support\SurveyLocale;
use Modules\CustomerSatisfaction\Models\SurveyDispatch;
use Modules\CustomerSatisfaction\Models\SurveyTemplate;

/**
 * Turns an ERP event into a survey dispatch: picks the matching rule, applies the fatigue throttling and schedules the invitation
 * `delay_hours` after the event. Throttled and suppressed invitations are stored too, so every decision stays auditable.
 * Safe to repeat: the same rule never surveys the same source record twice.
 */
final class SurveyScheduler
{
    /** Dimensions copied onto the dispatch and, later, onto the answer. */
    private const array Dimensions = ['department_code', 'technician_id', 'product_line_code', 'branch_code'];

    public function __construct(private readonly SurveyThrottler $throttler) {}

    public function handle(SurveyTriggerContext $context, ?string $tenantId = null): ?SurveyDispatch
    {
        $template = SurveyTemplate::query()
            ->where('trigger_event', $context->event->value)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('code')
            ->get()
            ->first(fn (SurveyTemplate $candidate): bool => $this->matches($candidate, $context));

        if ($template === null) {
            return null;
        }

        if ($context->sourceEntityId !== null) {
            $existing = SurveyDispatch::query()
                ->where('survey_template_id', $template->id)
                ->where('trigger_event', $context->event->value)
                ->where('source_entity_type', $context->sourceEntityType)
                ->where('source_entity_id', $context->sourceEntityId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $contact = Contact::query()->find($context->customerId);
        [$channel, $recipientFound] = $this->channelFor($template, $contact);
        $reason = null;
        $status = DispatchStatus::Scheduled;

        if ($contact === null || ! $recipientFound) {
            $status = DispatchStatus::Suppressed;
            $reason = ThrottleReason::MissingContact;
        } elseif (($reason = $this->throttler->reasonFor($template, $context->customerId, $channel)) !== null) {
            $status = $reason === ThrottleReason::OptOut ? DispatchStatus::Suppressed : DispatchStatus::Throttled;
        }

        $occurredAt = $context->occurredAt ?? now();
        $dimensions = $this->dimensionsOf($template, $context);

        return DB::transaction(fn (): SurveyDispatch => SurveyDispatch::query()->create([
            'tenant_id' => $tenantId ?? $template->tenant_id,
            'survey_template_id' => $template->id,
            'customer_id' => $context->customerId,
            'contact_id' => $contact?->id,
            'trigger_event' => $context->event->value,
            'source_entity_type' => $context->sourceEntityType,
            'source_entity_id' => $context->sourceEntityId,
            'channel' => $channel->value,
            'locale' => SurveyLocale::normalize($context->locale ?? SurveyLocale::forCountry($contact?->country)),
            'dispatch_token' => Str::random(48),
            'status' => $status->value,
            'throttle_reason' => $reason?->value,
            'scheduled_for' => $occurredAt->copy()->addHours($template->delay_hours),
            'metadata' => [
                'dimensions' => $dimensions,
                'cooldown_days' => $template->cooldown_days,
                'evaluated_at' => now()->toIso8601String(),
            ],
        ]));
    }

    /**
     * @return array{0: SurveyChannel, 1: bool} the first channel of the template the customer can be reached on
     */
    private function channelFor(SurveyTemplate $template, ?Contact $contact): array
    {
        $channels = array_values(array_filter(array_map(SurveyChannel::tryFrom(...), $template->channels)));
        $first = $channels[0] ?? SurveyChannel::Email;

        foreach ($channels as $channel) {
            $address = $channel === SurveyChannel::Email ? $contact?->email : $contact?->phone;

            if (is_string($address) && trim($address) !== '') {
                return [$channel, true];
            }
        }

        return [$first, false];
    }

    private function matches(SurveyTemplate $template, SurveyTriggerContext $context): bool
    {
        foreach ($template->trigger_filters ?? [] as $key => $expected) {
            if ($expected === null || $expected === [] || $expected === '') {
                continue;
            }

            $actual = $context->dimensions[$key === 'min_amount' ? 'amount' : $key] ?? null;

            if ($key === 'min_amount') {
                if ((float) $actual < (float) $expected) {
                    return false;
                }

                continue;
            }

            if (! in_array($actual, (array) $expected, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function dimensionsOf(SurveyTemplate $template, SurveyTriggerContext $context): array
    {
        $resolved = [];

        foreach (self::Dimensions as $dimension) {
            $resolved[$dimension] = $context->dimensions[$dimension] ?? ($template->dimensions[$dimension] ?? null);
        }

        return $resolved;
    }
}
