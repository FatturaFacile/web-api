<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\AdventureParks\Models\ParkConfig;
use Modules\AdventureParks\Models\SlotTemplate;

/**
 * Adventure parks (Mod 299): the global reference data (waiver legal text and workshop catalogue in it / en / de / fr, see
 * AdventureParksReferenceSeeder) and, for every existing company, its default park configuration and daily slots. Idempotent:
 * a company that already has a configuration or slots keeps them. Not part of TestingSeeder: the tests build what they need.
 */
final class AdventureParksDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AdventureParksReferenceSeeder::class);

        $context = app(TenantContext::class);

        foreach (Tenant::query()->get() as $tenant) {
            $context->runWithTenant($tenant, fn () => $this->seedTenant($tenant));
        }
    }

    private function seedTenant(Tenant $tenant): void
    {
        if (! ParkConfig::query()->exists()) {
            ParkConfig::query()->create([
                'tenant_id' => (string) $tenant->id,
                'buffer_minutes' => (int) config('adventure_parks.buffer_minutes'),
                'teacher_free_ratio' => (int) config('adventure_parks.teacher_free_ratio'),
                'teacher_free_per_disabled' => (int) config('adventure_parks.teacher_free_per_disabled'),
                'picnic_capacity' => (int) config('adventure_parks.picnic_capacity'),
                'waiver_version_id' => (string) config('adventure_parks.waiver_version_id'),
            ]);
        }

        if (SlotTemplate::query()->exists()) {
            return;
        }

        /** @var list<string> $palette */
        $palette = (array) config('adventure_parks.wristband_palette');

        foreach ((array) config('adventure_parks.default_slots') as $index => $slot) {
            SlotTemplate::query()->create([
                'tenant_id' => (string) $tenant->id,
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
                'wristband_color' => $palette[$index % count($palette)],
                'weekdays' => null,
                'is_active' => true,
            ]);
        }
    }
}
