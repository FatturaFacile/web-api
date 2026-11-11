<?php

declare(strict_types=1);

namespace Database\Factories\Privacy;

use App\Models\Privacy\PrivacyRetentionPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PrivacyRetentionPolicy>
 */
final class PrivacyRetentionPolicyFactory extends Factory
{
    protected $model = PrivacyRetentionPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'entry_key' => 'crm_leads',
            'retention_days' => 730,
            'action' => 'anonymise',
            'is_active' => true,
        ];
    }
}
