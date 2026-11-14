<?php

declare(strict_types=1);

namespace App\Support\Database\Concerns;

use App\Support\Database\Immutability;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Application-level replacement of the database triggers that made a record immutable, identical on every database.
 * The model says in recordProtectionViolation() when a create, update or delete is refused; the refusal is a
 * LogicException with the message returned there. Inside Immutability::lifted() nothing is refused.
 *
 * The guard runs on model events, so a mass update() or delete() of the query builder or a raw DB::table() statement
 * is not covered: code that changes these records goes through the model.
 *
 * @phpstan-require-extends Model
 */
trait ProtectsRecords
{
    public static function bootProtectsRecords(): void
    {
        static::creating(static fn (Model $model) => $model->assertRecordMutable('create'));
        static::updating(static fn (Model $model) => $model->assertRecordMutable('update'));
        static::deleting(static fn (Model $model) => $model->assertRecordMutable('delete'));
    }

    /**
     * The reason this change is refused, or null when it is allowed.
     *
     * @param  'create'|'update'|'delete'  $operation
     */
    abstract protected function recordProtectionViolation(string $operation): ?string;

    /**
     * @param  'create'|'update'|'delete'  $operation
     */
    public function assertRecordMutable(string $operation): void
    {
        if (Immutability::isLifted()) {
            return;
        }

        $violation = $this->recordProtectionViolation($operation);

        if ($violation !== null) {
            throw new LogicException($violation);
        }
    }

    /**
     * Whether any of the changed attributes lies outside the given list.
     *
     * @param  list<string>  $allowed
     */
    protected function changesOutside(array $allowed): bool
    {
        return array_diff(array_keys($this->getDirty()), $allowed) !== [];
    }
}
