<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\DocumentTrust\Models\PreservationDisposalProposal;
use Modules\DocumentTrust\Models\PreservationPackage;

/**
 * @extends Factory<PreservationDisposalProposal>
 */
final class PreservationDisposalProposalFactory extends Factory
{
    protected $model = PreservationDisposalProposal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'package_id' => PreservationPackage::factory(),
            'tenant_id' => static fn (array $attributes): string => (string) PreservationPackage::withoutGlobalScopes()->findOrFail($attributes['package_id'])->tenant_id,
            'status' => PreservationDisposalProposal::Pending,
            'expiry_date' => now()->subDay()->toDateString(),
            'proposed_by' => (string) Str::uuid(),
            'proposed_at' => now(),
        ];
    }
}
