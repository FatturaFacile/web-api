<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\LookupItem;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceReport>
 */
class ServiceReportFactory extends Factory
{
    protected $model = ServiceReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? Tenant::create([
            'name' => fake()->company(),
            'legal_name' => fake()->company(),
            'country' => 'IT',
            'default_currency' => 'EUR',
        ])->id;

        $draftStatus = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'service_report_status'))
            ->where('code', 'draft')
            ->first();

        return [
            'tenant_id' => $tenantId,
            'report_number' => 'SR-'.fake()->unique()->numerify('2026-####'),
            'status_id' => $draftStatus?->id ?? LookupItem::first()?->id,
            'contact_id' => Contact::first()?->id ?? Contact::factory(),
            'technician_id' => User::first()?->id ?? User::factory(),
            'report_date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'time_start' => '08:30',
            'time_end' => '12:30',
            'total_hours' => 4.0,
            'travel_distance_km' => 25.0,
            'site_address' => fake()->address(),
            'customer_notes' => 'Customer requested maintenance check on electrical panel.',
            'internal_notes' => 'Panel components tightened, one fuse replaced.',
            'total_net' => 250.00,
            'total_tax' => 55.00,
            'total_gross' => 305.00,
            'is_portal_visible' => true,
        ];
    }
}
