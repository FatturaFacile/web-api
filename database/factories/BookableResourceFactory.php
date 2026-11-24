<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\BookableResource;

/**
 * @extends Factory<BookableResource>
 */
class BookableResourceFactory extends Factory
{
    protected $model = BookableResource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => 'Court 1', 'kind' => 'court'];
    }
}
