<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Listeners;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\Events\ExchangeRatesSynchronized;
use Modules\Treasury\Currency\Domain\Exceptions\ExchangeRateMissingException;
use Modules\Treasury\Currency\Domain\Services\RateSelector;
use Modules\Treasury\Currency\Domain\Support\Dec;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;
use Modules\Treasury\Currency\Infrastructure\Notifications\ExchangeRateMovementNotification;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencyPositionProvider;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencySettingsRepository;
use Throwable;

/**
 * After a synchronisation: tells the finance team of each company when a currency in which it has open exposure moved more than
 * its threshold (a percentage, configurable in the currency settings) between the previous rate day and the latest one. One
 * alert per company, currency and day.
 */
final class EvaluateRateMovementAlerts
{
    public function __construct(
        private readonly RateSelector $selector,
        private readonly CurrencySettingsRepository $settings,
        private readonly CurrencyPositionProvider $positions,
    ) {}

    public function handle(ExchangeRatesSynchronized $event): void
    {
        $day = CarbonImmutable::parse($event->latestRateDate);

        foreach (Tenant::query()->cursor() as $tenant) {
            try {
                $this->evaluate((string) $tenant->id, $tenant, $day);
            } catch (Throwable $exception) {
                Log::warning('Rate movement alerts not evaluated.', ['tenant' => $tenant->id, 'reason' => $exception->getMessage()]);
            }
        }
    }

    private function evaluate(string $tenantId, Tenant $tenant, CarbonImmutable $day): void
    {
        $settings = $this->settings->effective($tenantId);

        if ($settings->alertThresholdPercent === null || ! Dec::isPositive($settings->alertThresholdPercent)) {
            return;
        }

        $currencies = array_values(array_unique(array_column($this->positions->positions($tenantId, $settings->functionalCurrency, $day->toDateString()), 'currency')));

        foreach ($currencies as $currency) {
            $change = $this->dailyChange($currency, $settings->functionalCurrency, $day, $tenantId);

            if ($change === null || Dec::cmp(Dec::abs($change), $settings->alertThresholdPercent) < 0) {
                continue;
            }

            if (! Cache::add("currency_fx:movement:{$tenantId}:{$currency}:{$day->toDateString()}", true, now()->addDays(2))) {
                continue;
            }

            $recipients = $tenant->users()->wherePivotIn('role', (array) config('currency_fx.notify_roles', ['owner', 'admin']))->get();

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new ExchangeRateMovementNotification($currency, $settings->functionalCurrency, Dec::round($change, 2), $day->toDateString()));
            }
        }
    }

    /**
     * Percentage change of the currency against the functional one between the latest rate day and the one before it, or null
     * when either rate is missing.
     */
    private function dailyChange(string $currency, string $functional, CarbonImmutable $day, string $tenantId): ?string
    {
        try {
            $pair = CurrencyPair::of($currency, $functional);
            $latest = $this->selector->select($pair, $day->toDateString(), $tenantId, RateType::Mid);
            $previous = $this->selector->select($pair, CarbonImmutable::parse($latest->validOn)->subDay()->toDateString(), $tenantId, RateType::Mid);
        } catch (ExchangeRateMissingException) {
            return null;
        }

        if ($previous->validOn === $latest->validOn) {
            return null;
        }

        return Dec::mul(Dec::div(Dec::sub($latest->rate->value, $previous->rate->value), $previous->rate->value), '100');
    }
}
