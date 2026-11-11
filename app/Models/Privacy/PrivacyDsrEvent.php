<?php

declare(strict_types=1);

namespace App\Models\Privacy;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One step in the history of a data subject request: who moved it from which status to which, and why.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $request_id
 * @property string $event
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $actor_id
 * @property string|null $note
 */
final class PrivacyDsrEvent extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $table = 'privacy_dsr_events';

    /**
     * Appends an event to the history of a request.
     */
    public static function record(PrivacyDataSubjectRequest $request, string $event, ?string $fromStatus, ?string $toStatus, ?string $actorId = null, ?string $note = null): self
    {
        return self::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $request->tenant_id,
            'request_id' => (string) $request->id,
            'event' => $event,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_id' => $actorId !== null && Str::isUuid($actorId) ? $actorId : null,
            'note' => $note,
        ]);
    }
}
