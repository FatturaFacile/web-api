<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\KnowledgeArticle;

class KnowledgeArticleFactory extends Factory
{
    protected $model = KnowledgeArticle::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'article_number' => 'KB-'.strtoupper(Str::random(6)),
            'title' => fake()->sentence(),
            'summary' => fake()->sentence(),
            'content' => fake()->paragraphs(3, true),
            'is_published' => true,
            'views_count' => fake()->numberBetween(0, 500),
            'helpful_count' => fake()->numberBetween(0, 50),
            'tags' => ['help', 'faq', 'troubleshooting'],
        ];
    }
}
