<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Domain\Concerns;

use Modules\BondedWarehouses\Domain\Exceptions\BondedWarehouseException;

/**
 * Audit trail rows are append-only: they can be created, never changed or deleted.
 */
trait ImmutableRecord
{
    public static function bootImmutableRecord(): void
    {
        static::updating(static function (): never {
            throw BondedWarehouseException::because('record_immutable', [], 409);
        });

        static::deleting(static function (): never {
            throw BondedWarehouseException::because('record_immutable', [], 409);
        });
    }
}
