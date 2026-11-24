<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubscriptionInterval;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Workshop\Models\RecurringService;

/**
 * @extends Factory<RecurringService>
 */
final class RecurringServiceFactory extends Factory
{
    protected $model = RecurringService::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'vehicle_id' => static fn (array $attributes): string => (string) Vehicle::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'tire_hotel_record_id' => null,
            'subscription_id' => static function (array $attributes): string {
                $vehicle = Vehicle::query()->withoutGlobalScopes()->findOrFail($attributes['vehicle_id']);

                return (string) Subscription::query()->create([
                    'tenant_id' => $attributes['tenant_id'],
                    'contact_id' => $vehicle->client_id,
                    'subscription_number' => strtoupper(fake()->unique()->bothify('ABB-2026-#####')),
                    'name' => 'Deposito pneumatici stagionale',
                    'status' => 'active',
                    'interval' => SubscriptionInterval::Semiannual,
                    'interval_count' => 1,
                    'currency' => 'CHF',
                    'start_date' => '2026-04-01',
                    'auto_renew' => true,
                    'notes' => "Vehicle {$vehicle->license_plate}",
                ])->id;
            },
            'service_type' => 'tyre_storage',
        ];
    }
}
