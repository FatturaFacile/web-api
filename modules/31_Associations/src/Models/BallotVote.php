<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * A vote cast. For a secret ballot the row holds the choice only: no person, no timestamp and a random identifier.
 * Append-only.
 *
 * @property string $choice
 * @property ?string $participant_id
 */
final class BallotVote extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $table = 'assoc_ballot_votes';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static fn () => throw new LogicException('Votes cannot be updated.'));
        self::deleting(static fn () => throw new LogicException('Votes cannot be deleted.'));
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }
}
