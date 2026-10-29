<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ServiceTestingSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        foreach ($tenants as $tenant) {
            // Testing calendar
            $existingCal = DB::table('esm_business_hours_calendars')
                ->where('tenant_id', $tenant->id)
                ->where('code', 'CAL_TEST')
                ->value('id');
            $calId = $existingCal ?: (string) Str::uuid7();

            DB::table('esm_business_hours_calendars')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'code' => 'CAL_TEST'],
                [
                    'id' => $calId,
                    'company_id' => $tenant->id,
                    'name' => 'Default Testing Calendar',
                    'timezone' => 'UTC',
                    'weekly_schedule' => json_encode([
                        'mon' => ['09:00', '18:00'],
                        'tue' => ['09:00', '18:00'],
                        'wed' => ['09:00', '18:00'],
                        'thu' => ['09:00', '18:00'],
                        'fri' => ['09:00', '18:00'],
                    ]),
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            // Testing technician
            $existingTech = DB::table('esm_service_technicians')
                ->where('tenant_id', $tenant->id)
                ->where('email', 'test.tech@example.com')
                ->value('id');
            $techId = $existingTech ?: (string) Str::uuid7();

            DB::table('esm_service_technicians')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'email' => 'test.tech@example.com'],
                [
                    'id' => $techId,
                    'company_id' => $tenant->id,
                    'technician_number' => 'TECH-TEST-001',
                    'first_name' => 'Test Lead',
                    'last_name' => 'Technician',
                    'phone' => '+390212345678',
                    'current_status' => 'available',
                    'hourly_cost_rate' => 40.00,
                    'hourly_billable_rate' => 90.00,
                    'is_available' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
