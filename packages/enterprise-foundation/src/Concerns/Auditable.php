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
                    payloadAfter: $model->getAttributes()
                );
            }
        });

        static::updated(function ($model): void {
            if (app()->bound(AuditLedgerService::class)) {
                app(AuditLedgerService::class)->record(
                    action: 'UPDATED',
                    model: $model,
                    payloadBefore: $model->getOriginal(),
                    payloadAfter: $model->getChanges()
                );
            }
        });

        static::deleted(function ($model): void {
            if (app()->bound(AuditLedgerService::class)) {
                app(AuditLedgerService::class)->record(
                    action: 'DELETED',
                    model: $model,
                    payloadBefore: $model->getOriginal(),
                    payloadAfter: null
                );
            }
        });
    }
}
