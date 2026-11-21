<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A chunk of the tenant knowledge index with its vector. The vector is JSON text, so the cosine ranking runs the same on
 * SQLite and PostgreSQL; a tenant never reads another tenant's chunks (BelongsToTenant).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $source_type
 * @property string $source_id
 * @property int $chunk_index
 * @property string $content
 * @property string $content_hash
 * @property list<float> $embedding_json
 * @property int $dimension
 * @property string $model
 * @property array<string, mixed>|null $metadata_json
 */
final class AiEmbedding extends Model
{
    use BelongsToTenant, HasUuidV7, SoftDeletes;

    protected $table = 'ai_embeddings';

    protected $guarded = ['id', 'created_at', 'updated_at', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'embedding_json' => 'array',
            'metadata_json' => 'array',
            'chunk_index' => 'integer',
            'dimension' => 'integer',
        ];
    }
}
