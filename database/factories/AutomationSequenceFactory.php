<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CRM\Models\AutomationSequence;

/**
 * @extends Factory<AutomationSequence>
 */
final class AutomationSequenceFactory extends Factory
{
    protected $model = AutomationSequence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(3, true),
            'entity' => 'leads',
            'trigger_type' => 'manual',
            'exit_conditions' => ['lead_converted', 'consent_withdrawn'],
            'is_active' => true,
        ];
    }
}
