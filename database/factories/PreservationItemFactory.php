<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\DocumentTrust\Models\PreservationItem;
use Modules\DocumentTrust\Models\PreservationPackage;

/**
 * @extends Factory<PreservationItem>
 */
final class PreservationItemFactory extends Factory
{
    protected $model = PreservationItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = Str::random(64);

        return [
            'package_id' => PreservationPackage::factory(),
            'tenant_id' => static fn (array $attributes): string => (string) PreservationPackage::withoutGlobalScopes()->findOrFail($attributes['package_id'])->tenant_id,
            'sequence' => 1,
            'document_class' => 'issued_invoice',
            'source_type' => 'invoice',
            'source_id' => (string) Str::uuid(),
            'file_name' => 'invoice.xml',
            'mime_type' => 'application/xml',
            'size' => strlen($content),
            'sha256' => hash('sha256', $content),
            'storage_disk' => 'local',
            'storage_path' => 'tenants/test/preservation/'.Str::uuid().'.xml',
            'document_type' => 'invoice',
            'document_date' => '2027-01-15',
            'document_number' => 'FT-1',
            'counterpart_name' => 'Cliente Srl',
            'counterpart_vat_number' => '01234567890',
            'own_vat_number' => '00811720580',
        ];
    }
}
