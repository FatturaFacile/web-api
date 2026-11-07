<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\EDocumentNetworks\Models\EDocumentTransmission;

/**
 * @extends Factory<EDocumentTransmission>
 */
final class EDocumentTransmissionFactory extends Factory
{
    protected $model = EDocumentTransmission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'network' => 'peppol',
            'document_reference' => 'INV-1',
            'recipient_identifier' => 'test',
            'payload_sha256' => hash('sha256', 'x'),
            'status' => 'simulated',
            'driver' => 'local-peppol',
        ];
    }
}
