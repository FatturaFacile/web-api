<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\SafetyDataSheetDelivery;

/**
 * @extends Factory<SafetyDataSheetDelivery>
 */
final class SafetyDataSheetDeliveryFactory extends Factory
{
    protected $model = SafetyDataSheetDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'contact_id' => static fn (array $attributes): string => (string) Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'delivery_note_id' => static fn (array $attributes): string => (string) SalesDeliveryNoteFactory::new()->create([
                'tenant_id' => $attributes['tenant_id'],
                'contact_id' => $attributes['contact_id'],
                'sales_order_id' => null,
            ])->id,
            'safety_data_sheet_version_id' => static fn (array $attributes): string => (string) SafetyDataSheetVersionFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'email' => fake()->safeEmail(),
            'status' => 'sent',
            'error' => null,
            'sent_at' => '2026-10-30 11:15:00',
        ];
    }
}
