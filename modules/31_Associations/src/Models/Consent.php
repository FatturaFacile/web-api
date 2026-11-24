<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use LogicException;

/**
 * A consent given for a purpose of a processing activity, with the proof of how it was collected and the version of
 * the text shown. A withdrawal is recorded on the row; nothing else of it can change.
 *
 * @property string $id
 * @property ?string $withdrawn_at
 */
final class Consent extends AssociationEntry
{
    protected $table = 'assoc_consents';

    protected static function booted(): void
    {
        self::updating(static function (self $consent): void {
            if (array_diff(array_keys($consent->getDirty()), ['withdrawn_at', 'updated_at']) !== [] || $consent->getOriginal('withdrawn_at') !== null) {
                throw new LogicException('A consent record only accepts its withdrawal.');
            }
        });
        self::deleting(static fn () => throw new LogicException('Consent records are kept as proof.'));
    }

    protected function casts(): array
    {
        return ['proof' => 'encrypted', 'given_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    }
}
