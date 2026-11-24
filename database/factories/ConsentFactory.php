<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Consent;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\ProcessingActivity;

/**
 * @extends Factory<Consent>
 */
final class ConsentFactory extends Factory
{
    protected $model = Consent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $proof = 'Modulo di iscrizione cartaceo firmato, casella newsletter spuntata.';

        return [
            'tenant_id' => Tenant::factory(),
            'member_id' => static fn (array $attributes): string => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'activity_id' => static fn (array $attributes): string => ProcessingActivity::factory()->consentBased()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'purpose' => 'Invio della newsletter dell\'associazione',
            'method' => 'paper_form',
            'text_version' => 'informativa-2026-v1',
            'proof' => $proof,
            'proof_hash' => hash('sha256', $proof),
            'given_at' => now()->subMonth(),
        ];
    }
}
