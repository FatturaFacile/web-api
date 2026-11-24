<?php

declare(strict_types=1);

namespace Modules\Analytics\Models;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
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
 * @property array<string, mixed> $pivot
 * @property list<array{key: string, label: string, formula: string, format: string}> $calculated_fields
 * @property list<array<string, mixed>> $conditional_formatting
 * @property array<string, mixed> $chart_config
 * @property string $visibility private, role_shared or tenant_public
 * @property list<string> $shared_role_ids
 * @property bool $is_shared
 * @property list<string> $shared_with
 */
#[UseFactory(DesignedReportFactory::class)]
final class DesignedReport extends BaseEnterpriseModel
{
    public const string VisibilityPrivate = 'private';

    public const string VisibilityRoleShared = 'role_shared';

    public const string VisibilityTenantPublic = 'tenant_public';

    public const array Visibilities = [self::VisibilityPrivate, self::VisibilityRoleShared, self::VisibilityTenantPublic];

    protected $table = 'analytics_designed_reports';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['spec' => 'array', 'shared_with' => 'array', 'is_shared' => 'boolean', 'shared_role_ids' => 'array', 'pivot' => 'array', 'calculated_fields' => 'array', 'conditional_formatting' => 'array', 'chart_config' => 'array'];
    }

    /**
     * Reports the user may open: their own, the ones shared with the company and the ones shared with them.
     *
     * @param  Builder<DesignedReport>  $query
     * @return Builder<DesignedReport>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $role = $user->getRoleInTenant((string) app(TenantContext::class)->getTenantId());

        return $query->where(static fn (Builder $inner): Builder => $inner
            ->where('is_shared', true)
            ->orWhere('visibility', self::VisibilityTenantPublic)
            ->orWhere('user_id', $user->id)
            ->orWhereJsonContains('shared_with', (string) $user->id)
            ->when($role !== null, static fn (Builder $roles): Builder => $roles->orWhere(static fn (Builder $shared): Builder => $shared->where('visibility', self::VisibilityRoleShared)->whereJsonContains('shared_role_ids', $role))));
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
