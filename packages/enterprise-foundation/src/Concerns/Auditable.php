<?php

declare(strict_types=1);

namespace Enterprise\Foundation\Concerns;

use Enterprise\AuditLedger\Services\AuditLedgerService;

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
                    payloadAfter: $model->auditPayload($model->getAttributes())
                );
            }
        });

        static::updated(function ($model): void {
            if (app()->bound(AuditLedgerService::class)) {
                app(AuditLedgerService::class)->record(
                    action: 'UPDATED',
                    model: $model,
                    payloadBefore: $model->auditPayload($model->getRawOriginal()),
                    payloadAfter: $model->auditPayload($model->getChanges())
                );
            }
        });

        static::deleted(function ($model): void {
            if (app()->bound(AuditLedgerService::class)) {
                app(AuditLedgerService::class)->record(
                    action: 'DELETED',
                    model: $model,
                    payloadBefore: $model->auditPayload($model->getRawOriginal()),
                    payloadAfter: null
                );
            }
        });
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
}
