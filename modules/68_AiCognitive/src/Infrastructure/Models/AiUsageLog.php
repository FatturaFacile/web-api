<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One model call: tokens, cost, latency and outcome. It never holds the prompt.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $user_id
 * @property string $provider
 * @property string|null $model
 * @property string $task_type
 * @property string $data_classification
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property int $total_tokens
 * @property string $cost_usd
 * @property int $latency_ms
 * @property bool $cache_hit
 * @property bool $pii_masked
 * @property string $status
 * @property string|null $error
 */
final class AiUsageLog extends Model
{
    use BelongsToTenant, HasUuidV7, SoftDeletes;

    protected $table = 'ai_usage_logs';

    protected $guarded = ['id', 'created_at', 'updated_at', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
            'cost_usd' => 'decimal:6',
            'latency_ms' => 'integer',
            'cache_hit' => 'boolean',
            'pii_masked' => 'boolean',
        ];
    }
}
