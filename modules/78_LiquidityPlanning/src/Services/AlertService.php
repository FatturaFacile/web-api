<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\LiquidityPlanning\Mail\LiquidityAlertMail;
use Modules\LiquidityPlanning\Models\LiquidityAlert;
use Modules\LiquidityPlanning\Models\LiquiditySettings;
use Modules\Platform\Services\TenantNotifier;
use Modules\Treasury\Models\LoanAgreement;

/**
 * Evaluates the early-warning conditions of the baseline forecast and keeps the alerts in step: a condition raises one alert
 * (negative projected bank balance, DSCR below the threshold, capital loss, leverage or covenant breach), notified on the
 * dashboard and by email; the same condition does not notify again within the cooldown window, and an alert whose condition
 * is gone resolves itself. Acknowledged alerts stay visible until the condition ends.
 */
final readonly class AlertService
{
    public function __construct(
        private ForecastService $forecast,
        private TenantNotifier $notifier,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function present(LiquidityAlert $alert): array
    {
        return [
            'id' => (string) $alert->id,
            'type' => $alert->type,
            'severity' => $alert->severity,
            'status' => $alert->status,
            'title_key' => $alert->title_key,
            'message_key' => $alert->message_key,
            'params' => $alert->params ?? [],
            'detected_at' => $alert->detected_at->toIso8601String(),
            'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),
            'resolved_at' => $alert->resolved_at?->toIso8601String(),
            'last_notified_at' => $alert->last_notified_at?->toIso8601String(),
        ];
    }

    /**
     * Evaluates the conditions now, raises, refreshes and resolves the alerts and sends the notifications due.
     *
     * @return list<LiquidityAlert> the alerts open or acknowledged afterwards
     */
    public function evaluate(Tenant $tenant, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $core = $this->forecast->core($tenant, null, $now->startOfDay());
        $settings = $core['settings'];
        $conditions = $this->conditions($core, $settings);
        $active = [];

        foreach ($conditions as $condition) {
            $active[$condition['fingerprint']] = true;
            $alert = LiquidityAlert::query()->where('fingerprint', $condition['fingerprint'])->whereIn('status', [LiquidityAlert::Open, LiquidityAlert::Acknowledged])->first();

            if ($alert === null) {
                $alert = LiquidityAlert::query()->create($condition + ['status' => LiquidityAlert::Open, 'detected_at' => $now]);
            } else {
                $alert->update(['severity' => $condition['severity'], 'title_key' => $condition['title_key'], 'message_key' => $condition['message_key'], 'params' => $condition['params']]);
            }

            if ($this->notificationDue($alert, $settings, $now)) {
                $this->notify($tenant, $alert, $settings);
                $alert->update(['last_notified_at' => $now]);
            }
        }

        foreach (LiquidityAlert::query()->whereIn('status', [LiquidityAlert::Open, LiquidityAlert::Acknowledged])->get() as $alert) {
            if (! isset($active[$alert->fingerprint])) {
                $alert->update(['status' => LiquidityAlert::Resolved, 'resolved_at' => $now]);
            }
        }

        return LiquidityAlert::query()->whereIn('status', [LiquidityAlert::Open, LiquidityAlert::Acknowledged])->orderByDesc('detected_at')->get()->all();
    }

    public function acknowledge(LiquidityAlert $alert, ?string $userId): LiquidityAlert
    {
        if ($alert->status === LiquidityAlert::Open) {
            $alert->update(['status' => LiquidityAlert::Acknowledged, 'acknowledged_at' => now(), 'acknowledged_by' => $userId]);
        }

        return $alert->refresh();
    }

    public function resolve(LiquidityAlert $alert): LiquidityAlert
    {
        if ($alert->status !== LiquidityAlert::Resolved) {
            $alert->update(['status' => LiquidityAlert::Resolved, 'resolved_at' => now()]);
        }

        return $alert->refresh();
    }

    /**
     * @param  array<string, mixed>  $core
     * @return list<array<string, mixed>>
     */
    private function conditions(array $core, LiquiditySettings $settings): array
    {
        $indicators = $core['baselineIndicators'];
        $currency = (string) $core['currency'];
        $conditions = [];

        $liquidity = $indicators['liquidity'];

        if ($liquidity['status'] === 'negative') {
            $negative = $core['baseline']['negative_balance'];
            $conditions[] = $this->condition('negative_balance', 'negative_balance', 'critical', 'lpApi.alert.negative_balance.title', 'lpApi.alert.negative_balance.message', ['date' => $negative['first_date'], 'amount' => $negative['min_balance'], 'min_date' => $negative['min_date'], 'currency' => $currency]);
        } elseif ($liquidity['status'] === 'watch') {
            $conditions[] = $this->condition('negative_balance', 'negative_balance', 'warning', 'lpApi.alert.minimum_liquidity.title', 'lpApi.alert.minimum_liquidity.message', ['minimum' => $liquidity['minimum_balance'], 'amount' => $liquidity['lowest_balance'], 'currency' => $currency]);
        }

        $dscr = $indicators['dscr'];

        if (in_array($dscr['status'], ['critical', 'warning'], true)) {
            $threshold = $dscr['status'] === 'critical' ? $dscr['thresholds']['critical'] : $dscr['thresholds']['warning'];
            $conditions[] = $this->condition('dscr_below_threshold', 'dscr', $dscr['status'] === 'critical' ? 'critical' : 'warning', 'lpApi.alert.dscr.title', 'lpApi.alert.dscr.message', ['value' => $dscr['value'], 'threshold' => $threshold, 'operating_cash_flow' => $dscr['operating_cash_flow'], 'debt_service' => $dscr['debt_service']['total'], 'currency' => $currency]);
        }

        $capital = $indicators['capital'];

        if ($capital['configured'] && $capital['status'] !== 'ok') {
            $conditions[] = $this->condition('capital_loss', 'capital', $capital['status'] === 'watch' ? 'warning' : 'critical', 'lpApi.alert.capital.title', 'lpApi.alert.capital.'.$capital['status'], ['regime' => $capital['regime'], 'net_equity' => $capital['net_equity'], 'share_capital' => $capital['share_capital'], 'half_capital' => $capital['half_capital'], 'currency' => $currency]);
        }

        $pfn = $indicators['pfn'];

        if (in_array($pfn['status'], ['breach', 'watch'], true)) {
            $conditions[] = $this->condition('pfn_leverage', 'pfn', $pfn['status'] === 'breach' ? 'critical' : 'warning', 'lpApi.alert.pfn.title', 'lpApi.alert.pfn.message', ['pfn' => $pfn['pfn'], 'ebitda' => $pfn['ebitda'], 'ratio' => $pfn['pfn_ebitda'] ?? '-', 'max_ratio' => $pfn['max_ratio'], 'currency' => $currency]);
        }

        return [...$conditions, ...$this->covenants($indicators, $core)];
    }

    /**
     * The covenants of the active loans (`covenant_clause_json`: a list of `{metric, value}` where the metric is `dscr` (minimum),
     * `pfn_ebitda` (maximum) or `min_cash` (minimum balance over the horizon)).
     *
     * @param  array<string, mixed>  $indicators
     * @param  array<string, mixed>  $core
     * @return list<array<string, mixed>>
     */
    private function covenants(array $indicators, array $core): array
    {
        $found = [];

        foreach (LoanAgreement::query()->where('status', 'active')->whereNotNull('covenant_clause_json')->get() as $loan) {
            foreach ((array) $loan->covenant_clause_json as $index => $clause) {
                if (! isset($clause['value']) || ! is_numeric($clause['value'])) {
                    continue;
                }

                $metric = (string) ($clause['metric'] ?? '');
                $limit = (string) $clause['value'];

                $actual = match ($metric) {
                    'dscr' => $indicators['dscr']['value'],
                    'pfn_ebitda' => $indicators['pfn']['pfn_ebitda'],
                    'min_cash' => $indicators['liquidity']['lowest_balance'],
                    default => null,
                };
                $breached = $actual !== null && ($metric === 'pfn_ebitda' ? Decimal::compare($actual, $limit) > 0 : Decimal::compare($actual, $limit) < 0);

                if ($breached) {
                    $found[] = $this->condition('covenant_breach', 'covenant:'.$loan->id.':'.$metric.':'.$index, 'critical', 'lpApi.alert.covenant.title', 'lpApi.alert.covenant.message', ['loan' => (string) $loan->loan_reference, 'metric' => $metric, 'actual' => $actual, 'limit' => Decimal::round2($limit), 'currency' => $core['currency']]);
                }
            }
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function condition(string $type, string $key, string $severity, string $title, string $message, array $params): array
    {
        return ['type' => $type, 'severity' => $severity, 'fingerprint' => hash('sha256', $type.'|'.$key), 'title_key' => $title, 'message_key' => $message, 'params' => $params];
    }

    private function notificationDue(LiquidityAlert $alert, LiquiditySettings $settings, CarbonImmutable $now): bool
    {
        if ($alert->status === LiquidityAlert::Acknowledged) {
            return false;
        }

        return $alert->last_notified_at === null || $alert->last_notified_at->addHours($settings->alert_cooldown_hours)->lessThanOrEqualTo($now);
    }

    private function notify(Tenant $tenant, LiquidityAlert $alert, LiquiditySettings $settings): void
    {
        $locale = $tenant->country === 'CH' ? 'de' : 'it';
        $params = (array) $alert->params;
        $title = LiquidityTexts::get($alert->title_key, $locale);
        $body = LiquidityTexts::get($alert->message_key, $locale, $params);

        $this->notifier->notify((string) $tenant->id, 'liquidity_alert', $title, $body, '/liquidity-planning');

        if (! $settings->email_alerts_enabled) {
            return;
        }

        $emails = array_values(array_filter((array) $settings->notify_emails, static fn (string $email): bool => $email !== ''));

        if ($emails === []) {
            $emails = $tenant->users()->wherePivotIn('role', ['owner', 'admin'])->pluck('email')->all();
        }

        foreach (array_unique($emails) as $email) {
            Mail::to($email)->queue(new LiquidityAlertMail($title, $body, $alert->severity, $locale));
        }
    }
}
