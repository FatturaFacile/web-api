<?php

declare(strict_types=1);

namespace Enterprise\MultiTenant\Concerns;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\MultiTenant\Exceptions\MissingTenantContextException;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tenant policy of a model.
 *
 * Every tenant table has a nullable tenant_id. A null tenant_id means "global": the row is visible to every tenant, and
 * it is legitimate only for the models that use AllowsGlobalRecords (lookups, translations, catalogues, legal
 * reference data). For every other model a null tenant_id is never readable and never writable:
 *
 * - creating: the tenant comes from the tenant context (an explicit context always wins), otherwise from an explicit
 *   tenant_id given by trusted console code, otherwise from the authenticated user; with none of them the creation
 *   throws MissingTenantContextException, unless the record was declared global with createGlobal(), makeGlobal() or
 *   TenantContext::runAsGlobal() and the model allows global records.
 * - reading: a tenant sees its own rows and, only for models allowing global records, the rows with a null tenant_id.
 *   Without a tenant context only global rows of such models are visible.
 * - updating and deleting: a global row cannot be changed from an explicit tenant context unless the caller is a
 *   platform administrator.
 */
trait BelongsToTenant
{
    /**
     * Set by makeGlobal(): this very instance is declared global for its next save.
     */
    protected bool $declaredGlobal = false;

    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(static fn (Model $model) => static::stampTenantOnCreating($model));
        static::updating(static fn (Model $model) => static::assertTenantChangeAllowed($model));
        static::deleting(static fn (Model $model) => static::assertTenantChangeAllowed($model));
    }

    /**
     * Whether a null tenant_id means "global, visible to every tenant" for this model.
     */
    public static function allowsGlobalRecords(): bool
    {
        static $allowed = [];

        return $allowed[static::class] ??= in_array(AllowsGlobalRecords::class, class_uses_recursive(static::class), true);
    }

    /**
     * Creates a global record (tenant_id null, visible to every tenant). The only sanctioned way to write shared data.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createGlobal(array $attributes = []): static
    {
        return app(TenantContext::class)->runAsGlobal(
            static fn (): Model => static::query()->create(array_merge($attributes, ['tenant_id' => null])),
        );
    }

    /**
     * Updates or creates a global record.
     *
     * @param  array<string, mixed>  $attributes  Search attributes
     * @param  array<string, mixed>  $values
     */
    public static function updateOrCreateGlobal(array $attributes, array $values = []): static
    {
        return app(TenantContext::class)->runAsGlobal(
            static fn (): Model => static::query()->updateOrCreate(array_merge($attributes, ['tenant_id' => null]), $values),
        );
    }

    /**
     * Finds or creates a global record.
     *
     * @param  array<string, mixed>  $attributes  Search attributes
     * @param  array<string, mixed>  $values
     */
    public static function firstOrCreateGlobal(array $attributes, array $values = []): static
    {
        return app(TenantContext::class)->runAsGlobal(
            static fn (): Model => static::query()->firstOrCreate(array_merge($attributes, ['tenant_id' => null]), $values),
        );
    }

    /**
     * Builds an unsaved global record; its first save() stores it with a null tenant_id.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function makeGlobal(array $attributes = []): static
    {
        return (new static)->forceFill(array_merge($attributes, ['tenant_id' => null]))->declareGlobal();
    }

    /**
     * Declares this very instance global for its next save (a factory state of generic rows uses it).
     *
     * @return $this
     */
    public function declareGlobal(): static
    {
        $this->declaredGlobal = true;

        return $this;
    }

    /**
     * @throws MissingTenantContextException
     * @throws AccessDeniedHttpException
     */
    protected static function stampTenantOnCreating(Model $model): void
    {
        $tenantContext = app(TenantContext::class);

        if ($tenantContext->isWritingGlobal() || $model->declaredGlobal) {
            if (! $model::allowsGlobalRecords()) {
                throw MissingTenantContextException::modelDoesNotAllowGlobal($model::class);
            }

            if ($tenantContext->isExplicit() && ! static::callerIsPlatformAdmin()) {
                throw new AccessDeniedHttpException('Global records can be created only by a platform administrator.');
            }

            $model->setAttribute('tenant_id', null);

            return;
        }

        if ($tenantContext->isExplicit()) {
            // Enforce tenant_id strictly from the active explicit TenantContext (e.g. HTTP request).
            $model->setAttribute('tenant_id', $tenantContext->getTenantId());

            return;
        }

        if (! empty($model->getAttribute('tenant_id'))) {
            return;
        }

        if ($tenantContext->hasTenant()) {
            $model->setAttribute('tenant_id', $tenantContext->getTenantId());

            return;
        }

        throw MissingTenantContextException::forModel($model::class);
    }

    /**
     * Generic records (tenant_id null) are shared by every tenant, so a request acting for one tenant can read them but
     * not change them, and cannot move a record to another tenant. Console and system code run without an explicit
     * tenant and may; so may a platform administrator.
     *
     * @throws AccessDeniedHttpException
     */
    protected static function assertTenantChangeAllowed(Model $model): void
    {
        if (! app(TenantContext::class)->isExplicit() || static::callerIsPlatformAdmin()) {
            return;
        }

        $original = $model->getOriginal();

        if (array_key_exists('tenant_id', $original) && $original['tenant_id'] === null) {
            throw new AccessDeniedHttpException('Generic records are read-only for tenant users.');
        }

        if (array_key_exists('tenant_id', $original) && $model->isDirty('tenant_id')) {
            throw new AccessDeniedHttpException('The tenant of a record cannot be changed from a tenant context.');
        }
    }

    protected static function callerIsPlatformAdmin(): bool
    {
        $user = auth()->user();

        return $user instanceof User && (bool) $user->is_platform_admin;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return $this
     */
    public function fill(array $attributes): static
    {
        if (isset($attributes['tenant_id']) && ! app(TenantContext::class)->isExplicit()) {
            $this->setAttribute('tenant_id', $attributes['tenant_id']);
            unset($attributes['tenant_id']);
        }

        return parent::fill($attributes);
    }

    /**
     * Scope query to a specific tenant ID, plus the global (null) records for the models that allow them.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeForTenant($query, ?string $tenantId = null)
    {
        $column = $this->qualifyColumn('tenant_id');
        $includesGlobal = static::allowsGlobalRecords();

        return $query->where(function ($q) use ($tenantId, $column, $includesGlobal): void {
            if (! empty($tenantId)) {
                $q->where($column, $tenantId);

                if ($includesGlobal) {
                    $q->orWhereNull($column);
                }
            } elseif ($includesGlobal) {
                $q->whereNull($column);
            } else {
                $q->whereRaw('1 = 0');
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id')->withDefault();
    }
}
