<?php

declare(strict_types=1);

namespace Database\Factories\Privacy;

use App\Models\Privacy\PrivacyRetentionRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PrivacyRetentionRun>
 */
final class PrivacyRetentionRunFactory extends Factory
{
    protected $model = PrivacyRetentionRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'entry_key' => 'crm_leads',
            'dry_run' => true,
            'matched_count' => 0,
            'processed_count' => 0,
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}
