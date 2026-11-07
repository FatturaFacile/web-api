<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Communication\Models\DeliveryLog;

/**
 * @extends Factory<DeliveryLog>
 */
final class DeliveryLogFactory extends Factory
{
    protected $model = DeliveryLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'channel' => 'email',
            'driver' => 'mail',
            'document_type' => 'invoice',
            'document_id' => static fn (array $attributes): string => (string) SalesInvoiceFactory::new()->create([
                'tenant_id' => $attributes['tenant_id'],
                'contact_id' => Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            ])->id,
            'recipient' => fake()->safeEmail(),
            'status' => 'sent',
            'provider_reference' => null,
            'error' => null,
            'sent_by' => null,
        ];
    }
}
