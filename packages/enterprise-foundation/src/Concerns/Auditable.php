<?php

declare(strict_types=1);

namespace Enterprise\Foundation\Concerns;

use Enterprise\AuditLedger\Application\Commands\AppendAuditEntryCommand;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\AuditLedger\Services\AuditLedgerService;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CRUD audit of a model on the probative ledger (Mod 005): created, updated (old/new values of the changed attributes
 * only), deleted (soft or hard) and restored.
 *
 * A model tunes what enters the chain with two properties:
 *  - `$auditExclude`: attributes that are never recorded (noisy counters, caches);
 *  - `$auditPersonal`: personal attributes recorded as pseudonym tokens, so that the right to erasure can remove them
 *    without touching a hash. Secrets (passwords, tokens, OTP codes) are never recorded whatever the model says.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model): void {
            if (app()->bound(AuditLedgerService::class)) {
                app(AuditLedgerService::class)->record(
                    action: 'CREATED',
                    model: $model,
                    payloadBefore: null,
                    payloadAfter: $model->auditPayload($model->getAttributes()),
                );
            }
        });

        static::updated(function ($model): void {
            if (app()->bound(AuditLedgerService::class)) {
                // A soft delete or a restore is recorded by its own event: the touched deleted_at is not an update.
                $changes = $model->getChanges();
                $updatedAtColumn = $model->getUpdatedAtColumn();
                $softDeleteColumn = in_array(SoftDeletes::class, class_uses_recursive($model), true) ? $model->getDeletedAtColumn() : null;

                if ($softDeleteColumn !== null && array_key_exists($softDeleteColumn, $changes) && array_diff(array_keys($changes), [$softDeleteColumn, $updatedAtColumn]) === []) {
                    return;
                }

                // The touched timestamp is not a change of the record.
                if ($updatedAtColumn !== null) {
                    unset($changes[$updatedAtColumn]);
                }

                // Attributes the model keeps out of the trail do not make an entry on their own.
                $changes = array_diff_key($changes, array_flip($model->auditExcludedAttributes()));

                if ($changes === []) {
                    return;
                }

                app(AuditLedgerService::class)->record(
                    action: 'UPDATED',
                    model: $model,
                    payloadBefore: $model->auditPayload($model->getRawOriginal()),
                    payloadAfter: $model->auditPayload($changes),
                );
            }
        });

        static::deleted(function ($model): void {
            if (app()->bound(AuditLedgerService::class)) {
                $soft = in_array(SoftDeletes::class, class_uses_recursive($model), true) && ! $model->isForceDeleting();

                app(AuditLedgerInterface::class)->append(AppendAuditEntryCommand::forModel(
                    action: 'DELETED',
                    model: $model,
                    before: $model->auditPayload($model->getRawOriginal()),
                    after: null,
                    tenantId: (string) ($model->getAttribute('tenant_id') ?? '00000000-0000-0000-0000-000000000000'),
                    companyId: (string) ($model->getAttribute('company_id') ?? $model->getAttribute('tenant_id') ?? '00000000-0000-0000-0000-000000000000'),
                    moduleCode: self::auditModuleCode($model),
                    personalAttributes: $model->auditPersonalAttributes(),
                    excludedAttributes: $model->auditExcludedAttributes(),
                    context: ['delete_mode' => $soft ? 'soft' : 'hard'],
                ));
            }
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function ($model): void {
                if (app()->bound(AuditLedgerService::class)) {
                    app(AuditLedgerService::class)->record(
                        action: 'RESTORED',
                        model: $model,
                        payloadBefore: null,
                        payloadAfter: $model->auditPayload(['restored' => true]),
                    );
                }
            });
        }
    }

    /**
     * What of the attributes enters the immutable ledger. A model holding personal data overrides this to leave it out.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditPayload(array $attributes): array
    {
        return $attributes;
    }

    /**
     * Attributes that are never recorded.
     *
     * @return list<string>
     */
    public function auditExcludedAttributes(): array
    {
        return property_exists($this, 'auditExclude') ? array_values((array) $this->auditExclude) : [];
    }

    /**
     * Attributes recorded as pseudonym tokens.
     *
     * @return list<string>
     */
    public function auditPersonalAttributes(): array
    {
        return property_exists($this, 'auditPersonal') ? array_values((array) $this->auditPersonal) : [];
    }

    private static function auditModuleCode(object $model): string
    {
        $class = $model::class;

        if (str_contains($class, 'Modules\\')) {
            return strtoupper(explode('\\', $class)[1] ?? 'ERP');
        }

        return 'CORE';
    }
}
