<?php

declare(strict_types=1);

namespace Modules\Analytics\Models;

use App\Models\User;
use Database\Factories\DesignedReportFactory;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A report designed on the semantic layer: the dataset and the choices of the designer (dimensions, measures, filters,
 * sorting, top-N, period and comparison) kept as the validated specification. It never stores SQL. It belongs to the
 * user who designed it and is visible to the whole company when shared, or to the users it is shared with.
 *
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property ?string $description
 * @property string $dataset
 * @property array<string, mixed> $spec
 * @property bool $is_shared
 * @property list<string> $shared_with
 */
#[UseFactory(DesignedReportFactory::class)]
final class DesignedReport extends BaseEnterpriseModel
{
    protected $table = 'analytics_designed_reports';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['spec' => 'array', 'shared_with' => 'array', 'is_shared' => 'boolean'];
    }

    /**
     * Reports the user may open: their own, the ones shared with the company and the ones shared with them.
     *
     * @param  Builder<DesignedReport>  $query
     * @return Builder<DesignedReport>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(static fn (Builder $inner): Builder => $inner
            ->where('is_shared', true)
            ->orWhere('user_id', $user->id)
            ->orWhereJsonContains('shared_with', (string) $user->id));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<ExportSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(ExportSchedule::class, 'designed_report_id');
    }
}
