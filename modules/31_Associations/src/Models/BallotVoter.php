<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * Who has voted on a ballot, one row per eligible person: the unique key refuses a second vote. For a secret ballot the
 * time is not kept, so the row cannot be matched with the vote. Append-only.
 *
 * @property string $participant_id
 */
final class BallotVoter extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $table = 'assoc_ballot_voters';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static fn () => throw new LogicException('Ballot voters cannot be updated.'));
        self::deleting(static fn () => throw new LogicException('Ballot voters cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['voted_at' => 'datetime'];
    }

    /**
     * A random identifier: an ordered one would let the order of the voters be matched with the order of the votes.
     */
    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }
}
