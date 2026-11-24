<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Models;

use App\Models\User;
use Database\Factories\BiDashboardFactory;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configurable dashboard: a grid of widgets and the filters (period and dimension filters) that apply to all of them.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $owner_id
 * @property string $name
 * @property string|null $description
 * @property string $visibility
 * @property array{period?: array<string, string>, filters?: list<array<string, mixed>>}|null $filters
 */
#[UseFactory(BiDashboardFactory::class)]
final class BiDashboard extends BaseEnterpriseModel
{
    protected $table = 'bi_dashboards';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['filters' => 'array'];
    }

    /**
     * Dashboards the user may open: their own, the ones shared with the company and the ones shared with them.
     *
     * @param  Builder<BiDashboard>  $query
     * @return Builder<BiDashboard>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(static fn (Builder $inner): Builder => $inner
            ->where('visibility', BiSavedQuery::Company)
            ->orWhere('owner_id', $user->id)
            ->orWhereIn('id', BiShare::query()->where('resource_type', BiShare::Dashboard)->where('user_id', $user->id)->select('resource_id')));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<BiDashboardWidget, $this>
     */
    public function widgets(): HasMany
    {
        return $this->hasMany(BiDashboardWidget::class, 'dashboard_id')->orderBy('layout_y')->orderBy('layout_x');
    }
}
