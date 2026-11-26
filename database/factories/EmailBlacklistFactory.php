<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmailBlacklist;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailBlacklist>
 */
class EmailBlacklistFactory extends Factory
{
    protected $model = EmailBlacklist::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'email' => $this->faker->unique()->safeEmail(),
            'reason' => EmailBlacklist::REASON_MANUAL,
            'notes' => null,
        ];
    }
}
