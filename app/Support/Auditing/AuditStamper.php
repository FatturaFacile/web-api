<?php

declare(strict_types=1);

namespace App\Support\Auditing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

/**
 * Fills created_by, updated_by and deleted_by of every Eloquent model from the authenticated user.
 *
 * It listens to the model events of all models, and only touches the audit columns the table has. Without an
 * authenticated user (CLI, seeders, queues) the columns stay as they are, so they remain nullable.
 */
final class AuditStamper
{
    /**
     * @var array<string, list<string>>
     */
    private static array $columns = [];

    private static bool $resolvingUser = false;

    public static function register(): void
    {
        Event::listen('eloquent.creating: *', static function (string $event, array $payload): void {
            $model = $payload[0] ?? null;
            if ($model instanceof Model) {
                self::stampSaving($model, true);
            }
        });

        Event::listen('eloquent.updating: *', static function (string $event, array $payload): void {
            $model = $payload[0] ?? null;
            if ($model instanceof Model) {
                self::stampSaving($model, false);
            }
        });

        Event::listen('eloquent.deleting: *', static function (string $event, array $payload): void {
            $model = $payload[0] ?? null;
            if ($model instanceof Model) {
                self::stampDeleting($model);
            }
        });
    }

    public static function flush(): void
    {
        self::$columns = [];
    }

    private static function stampSaving(Model $model, bool $creating): void
    {
        $userId = self::userId();
        if ($userId === null) {
            return;
        }

        // An explicit created_by (even null, a record made on behalf of nobody) is respected; only an unset one is stamped.
        if ($creating && ! array_key_exists('created_by', $model->getAttributes()) && self::hasColumn($model, 'created_by')) {
            $model->setAttribute('created_by', $userId);
        }

        if (self::hasColumn($model, 'updated_by') && ! $model->isDirty('updated_by')) {
            $model->setAttribute('updated_by', $userId);
        }
    }

    private static function stampDeleting(Model $model): void
    {
        $userId = self::userId();
        if ($userId === null || ! $model->exists || ! self::hasColumn($model, 'deleted_by')) {
            return;
        }

        if (! in_array(SoftDeletes::class, class_uses_recursive($model), true) || (method_exists($model, 'isForceDeleting') && $model->isForceDeleting())) {
            return;
        }

        $model->newQueryWithoutScopes()
            ->whereKey($model->getKey())
            ->update(['deleted_by' => $userId]);
        $model->setAttribute('deleted_by', $userId);
        $model->syncOriginalAttribute('deleted_by');
    }

    private static function userId(): ?string
    {
        // Resolving the user can itself save a model (Sanctum stamps `last_used_at` on the token while the guard is
        // still resolving), which fires "updating" again: without this guard the two recurse until memory runs out.
        if (self::$resolvingUser) {
            return null;
        }

        self::$resolvingUser = true;

        try {
            $user = Auth::user();
        } finally {
            self::$resolvingUser = false;
        }

        return $user instanceof User ? (string) $user->getKey() : null;
    }

    private static function hasColumn(Model $model, string $column): bool
    {
        $key = $model->getConnectionName().'.'.$model->getTable();
        self::$columns[$key] ??= Schema::connection($model->getConnectionName())->getColumnListing($model->getTable());

        return in_array($column, self::$columns[$key], true);
    }
}
