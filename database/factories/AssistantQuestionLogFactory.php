<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Assistant\Models\AssistantQuestionLog;

/**
 * @extends Factory<AssistantQuestionLog>
 */
final class AssistantQuestionLogFactory extends Factory
{
    protected $model = AssistantQuestionLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => (string) Str::uuid7(),
            'question' => 'crediti aperti',
            'resolver' => 'keywords',
            'query_key' => 'open_receivables',
            'parameters' => [],
            'outcome' => 'answered',
        ];
    }
}
