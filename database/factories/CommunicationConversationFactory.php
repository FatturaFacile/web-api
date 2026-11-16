<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationConversation;

/**
 * @extends Factory<CommunicationConversation>
 */
final class CommunicationConversationFactory extends Factory
{
    protected $model = CommunicationConversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'channel' => 'email',
            'counterparty_address' => fake()->safeEmail(),
            'counterparty_name' => fake()->name(),
            'subject' => fake()->sentence(4),
            'status' => 'open',
            'unread_count' => 0,
            'last_message_at' => now(),
        ];
    }
}
