<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\DocumentTrust\Models\PreservationRecord;

/**
 * @extends Factory<PreservationRecord>
 */
final class PreservationRecordFactory extends Factory
{
    protected $model = PreservationRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'document_type' => 'invoice',
            'document_id' => static fn (array $attributes): string => (string) SalesInvoiceFactory::new()->create([
                'tenant_id' => $attributes['tenant_id'],
                'contact_id' => Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            ])->id,
            'provider' => 'local',
            'status' => 'fingerprint_only',
            'reference' => null,
            'content_sha256' => hash('sha256', Str::random(256)),
        ];
    }
}
