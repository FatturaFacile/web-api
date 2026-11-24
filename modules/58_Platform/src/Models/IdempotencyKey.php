<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The stored outcome of a mutating request sent with an Idempotency-Key, scoped to the user (and the company in use).
 * A row without a response status is a request still being processed.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $user_id
 * @property string $idempotency_key
 * @property string $method
 * @property string $path
 * @property string $request_hash
 * @property int|null $response_status
 * @property array<string, string>|null $response_headers
 * @property string|null $response_body
 * @property Carbon $expires_at
 */
final class IdempotencyKey extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'idempotency_keys';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['response_headers' => 'array', 'response_status' => 'integer', 'expires_at' => 'datetime'];
    }
}
