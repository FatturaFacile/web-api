<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use LogicException;

/**
 * A statement prepared for the filing with the national register of third-sector bodies: a frozen snapshot with its
 * hash, and once filed the reference of the filing made on the register portal by the authorized person.
 *
 * @property string $id
 * @property int $year
 * @property string $form
 * @property string $status
 * @property array<string, mixed> $snapshot
 */
final class StatementFiling extends AssociationRecord
{
    protected $table = 'assoc_statement_filings';

    protected static function booted(): void
    {
        self::updating(static function (self $filing): void {
            if ($filing->isDirty(['snapshot', 'content_hash', 'year', 'form']) || $filing->getOriginal('status') === 'filed') {
                throw new LogicException('A prepared statement is frozen.');
            }
        });
    }

    protected function casts(): array
    {
        return ['year' => 'integer', 'snapshot' => 'array', 'filed_at' => 'datetime'];
    }
}
