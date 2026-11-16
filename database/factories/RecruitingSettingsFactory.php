<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\RecruitingSettings;

/**
 * @extends Factory<RecruitingSettings>
 */
class RecruitingSettingsFactory extends Factory
{
    protected $model = RecruitingSettings::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'careers_slug' => 'careers-'.strtolower(Str::random(8)),
            'careers_enabled' => true,
            'company_name' => 'Acme Ticino SA',
            'intro' => ['en' => 'Join our team', 'it-CH' => 'Unisciti al nostro team'],
            'privacy_notice' => ['en' => 'We process your data according to the nLPD and the GDPR.'],
            'privacy_policy_url' => 'https://example.com/privacy',
            'consent_text_version' => 'v1',
            'retention_default_months' => 6,
            'pipeline' => [],
        ];
    }
}
