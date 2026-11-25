<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Services;

use Illuminate\Support\Carbon;
use Modules\CustomerSatisfaction\Domain\Enums\DispatchStatus;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyChannel;
use Modules\CustomerSatisfaction\Domain\Enums\ThrottleReason;
use Modules\CustomerSatisfaction\Models\SurveyDispatch;
use Modules\CustomerSatisfaction\Models\SurveyOptOut;
use Modules\CustomerSatisfaction\Models\SurveyTemplate;

/**
 * Survey fatigue rules: a customer who opted out is never surveyed, and a customer who already received (or is about to receive)
 * a survey inside the cooldown of the template (60 days by default) is not surveyed again, whatever the campaign.
 */
final class SurveyThrottler
{
    public function reasonFor(SurveyTemplate $template, string $customerId, ?SurveyChannel $channel = null, ?Carbon $now = null): ?ThrottleReason
    {
        $optedOut = SurveyOptOut::query()
            ->where('customer_id', $customerId)
            ->where(fn ($query) => $query->whereNull('channel')->when($channel !== null, fn ($inner) => $inner->orWhere('channel', $channel?->value)))
            ->exists();

        if ($optedOut) {
            return ThrottleReason::OptOut;
        }

        return $this->lastSurveyAt($customerId, $template->cooldown_days, $now) !== null ? ThrottleReason::CooldownActive : null;
    }

    /**
     * The newest survey that counts for the cooldown, when it falls inside the window.
     */
    public function lastSurveyAt(string $customerId, int $cooldownDays, ?Carbon $now = null): ?Carbon
    {
        if ($cooldownDays <= 0) {
            return null;
        }

        $since = ($now ?? now())->copy()->subDays($cooldownDays);

        /** @var SurveyDispatch|null $last */
        $last = SurveyDispatch::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', DispatchStatus::counting())
            ->where('created_at', '>=', $since)
            ->latest('created_at')
            ->first();

        return $last?->created_at;
    }
}
