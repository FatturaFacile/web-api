<?php

declare(strict_types=1);

namespace Modules\Logistics\Concerns;

use Illuminate\Support\Str;

/**
 * Fills created_by / updated_by / deleted_by from the authenticated user (soft-delete aware).
 */
trait HasAuditUsers
{
    public static function bootHasAuditUsers(): void
    {
        static::creating(function ($model): void {
            $userId = self::auditUserId();
            if ($userId !== null) {
                $model->created_by ??= $userId;
                $model->updated_by ??= $userId;
            }
        });

        static::updating(function ($model): void {
            $userId = self::auditUserId();
            if ($userId !== null && ! $model->isDirty('updated_by')) {
                $model->updated_by = $userId;
            }
        });

        static::deleting(function ($model): void {
            $userId = self::auditUserId();
            if ($userId !== null && ! $model->isForceDeleting()) {
                $model->deleted_by = $userId;
                $model->saveQuietly();
            }
        });
    }

    private static function auditUserId(): ?string
    {
        $id = auth()->id();

        return $id !== null && Str::isUuid((string) $id) ? (string) $id : null;
    }
}
