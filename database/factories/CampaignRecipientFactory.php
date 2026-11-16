<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Communication\Models\CampaignRecipient;
use Modules\Communication\Models\CommunicationCampaign;

/**
 * @extends Factory<CampaignRecipient>
 */
final class CampaignRecipientFactory extends Factory
{
    protected $model = CampaignRecipient::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'campaign_id' => CommunicationCampaign::factory(),
            'address' => fake()->safeEmail(),
            'status' => 'pending',
        ];
    }
}
