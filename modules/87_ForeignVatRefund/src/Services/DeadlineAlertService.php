<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Services;

use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Modules\ForeignVatRefund\Models\FiscalRepresentativeMandate;
use Modules\ForeignVatRefund\Models\ForeignVatAlertLog;
use Modules\ForeignVatRefund\Notifications\ForeignVatAlertNotification;

/**
 * Sends the priority alerts of one company: 60, 30 and 15 days before the peremptory deadlines (30 June ESTV, 30 September EU / Italy)
 * for the unsubmitted recoverable VAT, the missed deadlines, and the missing or expiring fiscal representative mandates and certificates.
 * Every alert is sent once per level: the alert log makes a daily run safe to repeat.
 */
final class DeadlineAlertService
{
    public function __construct(private readonly ThresholdMonitor $monitor) {}

    /**
     * @return int number of alerts sent
     */
    public function run(Tenant $tenant, ?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $tenantId = (string) $tenant->id;
        $sent = 0;
        $handled = [];

        foreach ($this->monitor->overview($tenantId, $today) as $row) {
            $country = (string) $row['country_code'];
            $year = (int) $row['fiscal_year'];
            $params = ['country' => $country, 'year' => $year, 'deadline' => (string) $row['deadline_date'], 'days' => max(0, (int) $row['days_remaining']), 'amount' => (string) $row['accumulated_amount'], 'currency' => (string) $row['currency_code']];
            $level = $row['alert_level'];

            if ($level !== null) {
                $expired = $level === ThresholdMonitor::EXPIRED;
                $sent += $this->send($tenantId, "deadline:{$country}:{$year}", $expired ? 0 : (int) $level, $expired ? ForeignVatAlertNotification::DEADLINE_EXPIRED : ForeignVatAlertNotification::DEADLINE, $params) ? 1 : 0;
            }

            $status = (string) $row['representative']['status'];
            $expiry = (string) ($row['representative']['certificate_expiry_date'] ?? '');
            $type = match ($status) {
                'MISSING' => ForeignVatAlertNotification::MANDATE_MISSING,
                'CERTIFICATE_MISSING' => ForeignVatAlertNotification::CERTIFICATE_MISSING,
                'CERTIFICATE_EXPIRED' => ForeignVatAlertNotification::CERTIFICATE_EXPIRED,
                'CERTIFICATE_EXPIRING' => ForeignVatAlertNotification::CERTIFICATE_EXPIRING,
                default => null,
            };

            if ($type !== null) {
                $handled[$country] = true;
                $sent += $this->send($tenantId, "mandate:{$country}:{$status}:{$expiry}", 1, $type, $params + ['expiry' => $expiry]) ? 1 : 0;
            }
        }

        // Certificates that are about to expire (or expired) even when no VAT is waiting for that country.
        $warning = (int) config('foreign_vat_refund.certificate_warning_days', 60);

        foreach (FiscalRepresentativeMandate::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_active', true)->whereNull('deleted_at')->whereNotNull('certificate_expiry_date')->get() as $mandate) {
            $country = $mandate->country_code;

            if (isset($handled[$country])) {
                continue;
            }

            $days = (int) $today->diffInDays($mandate->certificate_expiry_date, false);

            if ($days <= $warning) {
                $expiry = $mandate->certificate_expiry_date->format('Y-m-d');
                $sent += $this->send($tenantId, "mandate:{$country}:".($days < 0 ? 'CERTIFICATE_EXPIRED' : 'CERTIFICATE_EXPIRING').":{$expiry}", 1, $days < 0 ? ForeignVatAlertNotification::CERTIFICATE_EXPIRED : ForeignVatAlertNotification::CERTIFICATE_EXPIRING, ['country' => $country, 'expiry' => $expiry, 'days' => max(0, $days)]) ? 1 : 0;
            }
        }

        return $sent;
    }

    /**
     * @param  array<string, string|int>  $params
     */
    private function send(string $tenantId, string $key, int $level, string $type, array $params): bool
    {
        // A more urgent level already sent covers the less urgent one.
        $already = ForeignVatAlertLog::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('alert_key', $key)->where('level', '<=', $level)->exists();

        if ($already) {
            return false;
        }

        $recipients = User::query()
            ->whereHas('tenants', fn ($query) => $query->where('tenants.id', $tenantId)->whereIn('tenant_user.role', (array) config('foreign_vat_refund.alert_roles')))
            ->get();

        if ($recipients->isEmpty()) {
            return false;
        }

        Notification::send($recipients, new ForeignVatAlertNotification($type, $params));
        ForeignVatAlertLog::query()->create(['tenant_id' => $tenantId, 'alert_key' => $key, 'level' => $level, 'sent_at' => now()]);

        return true;
    }
}
