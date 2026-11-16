<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Auth;

/**
 * Fills the `created_by` / `updated_by` audit columns from the authenticated user.
 */
trait TracksAuditUsers
{
    protected static function bootTracksAuditUsers(): void
    {
        static::creating(function ($model): void {
            $userId = Auth::id();
            if ($userId !== null) {
                $model->created_by ??= (string) $userId;
                $model->updated_by = (string) $userId;
            }
        });

        static::updating(function ($model): void {
            $userId = Auth::id();
            if ($userId !== null) {
                $model->updated_by = (string) $userId;
            }
        });
    }
}
