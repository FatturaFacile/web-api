<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Childcare\Models\Message;

/**
 * @extends Factory<Message>
 */
final class ChildcareMessageFactory extends Factory
{
    protected $model = Message::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'child_id' => static fn (array $attributes): string => (string) ChildcareChildFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'direction' => 'to_family',
            'body' => fake()->randomElement([
                'Venerdì faremo una gita al parco: ricordatevi scarpe comode e cappellino.',
                'Oggi ha mangiato tutto e ha dormito un\'ora e mezza.',
                'Vi ricordiamo di portare un cambio completo entro lunedì.',
            ]),
            'sender_user_id' => null,
            'sender_contact_id' => null,
            'read_at' => null,
        ];
    }
}
