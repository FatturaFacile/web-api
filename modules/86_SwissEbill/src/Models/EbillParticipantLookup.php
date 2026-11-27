<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Models;

use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Cached answer of the participant directory, so a customer is not looked up again on every screen.
 *
 * @property string $lookup_type
 * @property string $lookup_key_hash
 * @property bool $found
 * @property array<string, mixed>|null $result
 * @property Carbon $fetched_at
 * @property Carbon $expires_at
 */
final class EbillParticipantLookup extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'ebill_participant_lookups';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['found' => 'boolean', 'result' => 'array', 'fetched_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function isFresh(): bool
    {
        return $this->expires_at->isFuture();
    }
}
