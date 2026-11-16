<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationCampaign;

/**
 * @extends Factory<CommunicationCampaign>
 */
final class CommunicationCampaignFactory extends Factory
{
    protected $model = CommunicationCampaign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(3, true),
            'channel' => 'email',
            'purpose' => 'marketing',
            'subject' => fake()->sentence(3),
            'body' => 'Hello {{ name }}',
            'status' => 'draft',
        ];
    }
}
