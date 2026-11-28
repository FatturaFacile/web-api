<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Application\Services;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Modules\SkiOutdoorSchool\Domain\Enums\VatRegime;
use Modules\SkiOutdoorSchool\Models\SchoolSetting;

/**
 * The settings row of the active company (created with the defaults of config/ski_school.php on first use) and the unique, unguessable
 * references of bookings.
 */
final class SchoolSettingsService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function current(): SchoolSetting
    {
        $existing = SchoolSetting::query()->first();

        if ($existing !== null) {
            return $existing;
        }

        return SchoolSetting::query()->create([
            'tenant_id' => (string) $this->tenantContext->getTenantId(),
            'public_slug' => 'school-'.Str::lower(Str::random(12)),
            'booking_enabled' => false,
            'jurisdiction' => 'IT',
            'default_retention_mode' => (string) config('ski_school.retention.mode'),
            'default_retention_value' => (float) config('ski_school.retention.value'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public function update(array $data): SchoolSetting
    {
        $settings = $this->current();
        $settings->fill(array_intersect_key($data, array_flip(['public_slug', 'booking_enabled', 'jurisdiction', 'default_retention_mode', 'default_retention_value', 'meeting_point'])));
        $settings->save();

        return $settings->refresh();
    }

    public function vatRegime(): VatRegime
    {
        return VatRegime::exemptionFor($this->current()->jurisdiction);
    }

    public function reference(string $prefix): string
    {
        return $prefix.'-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
    }
}
