<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Privacy settings of a company, stored under tenants.settings.privacy.
 *
 * The length of the period in which a data subject request must be answered is a tenant setting. The default of
 * 30 days is the one already used by this system (one month: GDPR art. 12(3); 30 days: nLPD art. 25(7)). Both are
 * TO BE CONFIRMED by the data protection officer or counsel before relying on them: this code does not verify them
 * against the official texts. GDPR art. 12(3) allows a further extension of two months for complex or numerous
 * requests, with notice to the data subject within the first month.
 */
final class PrivacySettings
{
    public const int DefaultDeadlineDays = 30;

    public const int MinDeadlineDays = 1;

    public const int MaxDeadlineDays = 90;

    public const int MaxExtensionDays = 60;

    public function deadlineDays(string $tenantId): int
    {
        $stored = Tenant::query()->whereKey($tenantId)->value('settings');
        $settings = is_string($stored) ? json_decode($stored, true) : $stored;
        $days = is_array($settings) ? ($settings['privacy']['dsr_deadline_days'] ?? null) : null;

        return is_int($days) && $days >= self::MinDeadlineDays && $days <= self::MaxDeadlineDays ? $days : self::DefaultDeadlineDays;
    }

    public function setDeadlineDays(string $tenantId, int $days): int
    {
        if ($days < self::MinDeadlineDays || $days > self::MaxDeadlineDays) {
            throw new InvalidArgumentException('The deadline must be between '.self::MinDeadlineDays.' and '.self::MaxDeadlineDays.' days.');
        }

        DB::transaction(function () use ($tenantId, $days): void {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenantId);
            $settings = $tenant->settings ?? [];
            $settings['privacy']['dsr_deadline_days'] = $days;
            $tenant->update(['settings' => $settings]);
        });

        return $days;
    }

    /**
     * @return array{dsr_deadline_days: int, default_deadline_days: int, to_be_confirmed: bool, note: string}
     */
    public function toArray(string $tenantId): array
    {
        return [
            'dsr_deadline_days' => $this->deadlineDays($tenantId),
            'default_deadline_days' => self::DefaultDeadlineDays,
            'to_be_confirmed' => true,
            'note' => 'Statutory answer period of data subject requests: 30 days is the default of the system (GDPR art. 12(3) one month, nLPD art. 25(7) 30 days). To be confirmed by the data protection officer or counsel.',
        ];
    }
}
