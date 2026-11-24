<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\DocumentTrust\Models\PreservationDisposal;
use Modules\DocumentTrust\Models\PreservationPackage;

/**
 * @extends Factory<PreservationDisposal>
 */
final class PreservationDisposalFactory extends Factory
{
    protected $model = PreservationDisposal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $manifest = '{"files":[]}';

        return [
            'package_id' => PreservationPackage::factory(),
            'tenant_id' => static fn (array $attributes): string => (string) PreservationPackage::withoutGlobalScopes()->findOrFail($attributes['package_id'])->tenant_id,
            'proposal_id' => (string) Str::uuid(),
            'document_class' => 'issued_invoice',
            'package_name' => 'Fatture emesse',
            'items_count' => 0,
            'total_bytes' => 0,
            'retention_until' => now()->subYear()->toDateString(),
            'expiry_date' => now()->subYear()->toDateString(),
            'manifest_sha256' => hash('sha256', $manifest),
            'manifest_json' => $manifest,
            'files' => [],
            'proposed_by' => (string) Str::uuid(),
            'approved_by' => (string) Str::uuid(),
            'executed_by' => (string) Str::uuid(),
            'executed_at' => now(),
        ];
    }
}
