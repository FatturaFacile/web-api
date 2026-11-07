<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * Local calendar of a company. Instants are stored in UTC like everywhere else; the business day a sale or a closure
 * belongs to is the date on the wall clock of the company, which in Italy and Switzerland runs one or two hours ahead.
 *
 * The time zone comes from the `company.timezone` preference when set, otherwise from the country of the company.
 */
final readonly class TenantClock
{
    /**
     * Time zone of the countries the product is sold in. Any other country falls back to the application time zone.
     *
     * @var array<string, string>
     */
    public const COUNTRY_TIMEZONES = [
        'IT' => 'Europe/Rome',
        'SM' => 'Europe/San_Marino',
        'VA' => 'Europe/Vatican',
        'CH' => 'Europe/Zurich',
        'LI' => 'Europe/Vaduz',
    ];

    public function __construct(private TenantContext $tenantContext) {}

    /**
     * Time zone identifier of the company, or of the active company when none is given.
     */
    public function timezone(?Tenant $tenant = null): string
    {
        $tenant ??= $this->tenantContext->getTenant();
        $configured = $tenant?->settings['preferences']['company']['timezone'] ?? null;

        if (is_string($configured) && in_array($configured, DateTimeZone::listIdentifiers(), true)) {
            return $configured;
        }

        return self::COUNTRY_TIMEZONES[strtoupper((string) $tenant?->country)] ?? (string) config('app.timezone', 'UTC');
    }

    /**
     * The current instant on the wall clock of the company.
     */
    public function now(?Tenant $tenant = null): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone($tenant));
    }

    /**
     * The business day of the company right now, at midnight local time.
     */
    public function today(?Tenant $tenant = null): CarbonImmutable
    {
        return $this->now($tenant)->startOfDay();
    }

    /**
     * The business day an instant stored in UTC belongs to, as `Y-m-d`.
     */
    public function businessDate(\DateTimeInterface $instant, ?Tenant $tenant = null): string
    {
        return CarbonImmutable::instance($instant)->setTimezone($this->timezone($tenant))->toDateString();
    }
}
