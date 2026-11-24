<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationConversation;
use Modules\Communication\Models\ConversationMessage;

/**
 * @extends Factory<ConversationMessage>
 */
final class ConversationMessageFactory extends Factory
{
    protected $model = ConversationMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'conversation_id' => CommunicationConversation::factory(),
            'direction' => 'inbound',
            'channel' => 'email',
            'sender_address' => fake()->safeEmail(),
            'body' => fake()->sentence(),
            'occurred_at' => now(),
        ];
    }
}
