<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Communication\Models\ContactChannelPreference;

/**
 * @extends Factory<ContactChannelPreference>
 */
final class ContactChannelPreferenceFactory extends Factory
{
    protected $model = ContactChannelPreference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'contact_id' => (string) Str::uuid7(),
            'channel' => 'email',
            'is_opted_out' => true,
            'changed_at' => now(),
        ];
    }
}
