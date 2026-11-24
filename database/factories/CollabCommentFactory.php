<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Collaboration\Models\CollabComment;

/**
 * @extends Factory<CollabComment>
 */
final class CollabCommentFactory extends Factory
{
    protected $model = CollabComment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'commentable_type' => 'contact',
            'commentable_id' => (string) Str::uuid7(),
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
