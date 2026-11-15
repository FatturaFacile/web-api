<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Models;

use App\Models\User;
use Database\Factories\BiSavedQueryFactory;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A saved self-service query: a dataset, the validated specification of the semantic layer (dimensions, measures,
 * filters, sorting, period), the calculated measures it uses and, optionally, the pivot layout (rows, columns, values).
 * It can be snapshotted on a schedule.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $owner_id
 * @property string $name
 * @property string|null $description
 * @property string $dataset
 * @property array<string, mixed> $spec
 * @property list<string>|null $calculated_measures
 * @property array{rows: list<string>, columns: list<string>, values: list<string>}|null $pivot
 * @property string $visibility
 * @property string|null $snapshot_frequency
 * @property \Illuminate\Support\Carbon|null $next_snapshot_on
 */
#[UseFactory(BiSavedQueryFactory::class)]
final class BiSavedQuery extends BaseEnterpriseModel
{
    public const string Private = 'private';

    public const string Company = 'company';

    public const string Shared = 'shared';

    /** @var list<string> */
    public const array Visibilities = [self::Private, self::Company, self::Shared];

    /** @var list<string> */
    public const array SnapshotFrequencies = ['daily', 'weekly', 'monthly'];

    protected $table = 'bi_saved_queries';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['spec' => 'array', 'calculated_measures' => 'array', 'pivot' => 'array', 'next_snapshot_on' => 'date'];
    }

    /**
     * Queries the user may open: their own, the ones shared with the company and the ones shared with them.
     *
     * @param  Builder<BiSavedQuery>  $query
     * @return Builder<BiSavedQuery>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(static fn (Builder $inner): Builder => $inner
            ->where('visibility', self::Company)
            ->orWhere('owner_id', $user->id)
            ->orWhereIn('id', BiShare::query()->where('resource_type', BiShare::SavedQuery)->where('user_id', $user->id)->select('resource_id')));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<BiSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(BiSnapshot::class, 'source_id')->where('source_type', BiSnapshot::SavedQuery);
    }
}
