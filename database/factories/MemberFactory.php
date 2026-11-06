<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Enums\MemberStatus;
use Modules\Associations\Models\Member;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    protected $model = Member::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => fn (array $attributes): string => Contact::create([
                'first_name' => fake()->firstName(),
                'last_name' => fake()->lastName(),
                'email' => fake()->unique()->safeEmail(),
                'phone' => fake()->numerify('+39 3## #######'),
            ])->id,
            'status' => MemberStatus::Active,
            'joined_on' => now()->subYear()->toDateString(),
            'birth_date' => now()->subYears(30)->toDateString(),
            'tax_code' => strtoupper(fake()->unique()->bothify('??????##?##?###?')),
        ];
    }

    public function minor(): static
    {
        return $this->state(['birth_date' => now()->subYears(12)->toDateString()]);
    }
}
