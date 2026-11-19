<?php

declare(strict_types=1);

namespace App\Domain\Audit\Concerns;

use App\Domain\Audit\Contracts\AuditableEntityInterface;
use App\Domain\Audit\Enums\AuditEventType;
use App\Services\Audit\AuditTrailRecorder;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Writes the lifecycle of an Eloquent entity (created, updated, deleted, restored) to the immutable audit trail.
 *
 * A model customises it with $auditExclude (secrets, never written), $auditMask (written masked) and $auditTrackReads,
 * and implements AuditableEntityInterface (the methods below satisfy it).
 *
 * @phpstan-require-extends Model
 */
trait HasImmutableAuditTrail
{
    /**
     * Updated columns that never make an audit record by themselves.
     *
     * @var list<string>
     */
    private static array $auditNoise = ['updated_at', 'updated_by', 'created_at', 'created_by'];

    public static function bootHasImmutableAuditTrail(): void
    {
        static::created(static fn (Model $model) => self::auditLifecycle($model, AuditEventType::Created));
        static::updated(static fn (Model $model) => self::auditLifecycle($model, AuditEventType::Updated));
        static::deleted(static fn (Model $model) => self::auditLifecycle($model, AuditEventType::Deleted));

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::registerModelEvent('restored', static fn (Model $model) => self::auditLifecycle($model, AuditEventType::Restored));
        }
    }

    /**
     * @return list<string>
     */
    public function auditExcludedAttributes(): array
    {
        $extra = property_exists($this, 'auditExclude') ? array_map(strval(...), (array) $this->auditExclude) : [];

        return array_values(array_unique(array_merge(['password', 'remember_token'], $extra)));
    }

    /**
     * @return list<string>
     */
    public function auditMaskedAttributes(): array
    {
        return property_exists($this, 'auditMask') ? (array) $this->auditMask : [];
    }

    public function auditTracksReads(): bool
    {
        return property_exists($this, 'auditTrackReads') && (bool) $this->auditTrackReads;
    }

    public function auditEntityName(): string
    {
        return class_basename($this);
    }

    /**
     * @param  list<string>  $changedFields
     */
    public function auditEventTypeFor(AuditEventType $default, array $changedFields): AuditEventType
    {
        return $default;
    }

    /**
     * Masked attributes are masked from their clear value: a column with an encrypted cast holds ciphertext in the raw
     * attributes, and masking that would hide nothing useful.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $masked
     * @return array<string, mixed>
     */
    private static function auditClearMasked(Model $model, array $values, array $masked, bool $original): array
    {
        foreach ($masked as $key) {
            if (array_key_exists($key, $values) && $values[$key] !== null) {
                $clear = $original ? $model->getOriginal($key) : $model->getAttribute($key);
                $values[$key] = is_scalar($clear) ? (string) $clear : $values[$key];
            }
        }

        return $values;
    }

    private static function auditLifecycle(Model $model, AuditEventType $event): void
    {
        if (! config('audit.enabled', true) || ! $model instanceof AuditableEntityInterface) {
            return;
        }

        $recorder = app(AuditTrailRecorder::class);
        $excluded = $model->auditExcludedAttributes();
        $masked = $model->auditMaskedAttributes();
        $context = [];
        $old = null;
        $new = null;
        $changed = [];

        switch ($event) {
            case AuditEventType::Created:
                $new = $recorder->sanitise(self::auditClearMasked($model, $model->getAttributes(), $masked, false), $excluded, $masked);
                $changed = array_keys($new);

                break;

            case AuditEventType::Updated:
                $changes = array_diff_key($model->getChanges(), array_flip(self::$auditNoise));

                // restore() saves deleted_at = null: the RESTORED event records it, not a second UPDATED.
                if ($changes === [] || array_keys($changes) === ['deleted_at']) {
                    return;
                }

                $changed = array_keys($changes);
                $oldAttributes = array_intersect_key($model->getRawOriginal(), $changes);
                $old = $recorder->sanitise(self::auditClearMasked($model, $oldAttributes, $masked, true), $excluded, $masked);
                $new = $recorder->sanitise(self::auditClearMasked($model, $changes, $masked, false), $excluded, $masked);
                $changed = array_values(array_diff($changed, $excluded));
                $event = $model->auditEventTypeFor($event, $changed);

                break;

            case AuditEventType::Deleted:
                $old = $recorder->sanitise(self::auditClearMasked($model, $model->getAttributes(), $masked, false), $excluded, $masked);
                $changed = array_keys($old);

                if (method_exists($model, 'isForceDeleting')) {
                    $context['force_delete'] = (bool) $model->isForceDeleting();
                }

                break;

            case AuditEventType::Restored:
                $changed = ['deleted_at'];

                break;

            default:
                return;
        }

        $tenantId = $model->getAttribute('tenant_id') ?? app(TenantContext::class)->getTenantId();

        $recorder->record(
            event: $event,
            entityType: $model->auditEntityName(),
            entityId: (string) $model->getKey(),
            tenantId: $tenantId === null ? null : (string) $tenantId,
            oldValues: $old,
            newValues: $new,
            changedFields: $changed,
            context: $context,
        );
    }
}
