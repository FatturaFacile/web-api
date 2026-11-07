<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

/**
 * A registration of the protocol of the correspondence, numbered per year without gaps. An entry is never deleted: a
 * wrong one is cancelled with a reason and keeps its number.
 *
 * @property string $id
 * @property string $number
 * @property ?string $cancelled_at
 */
final class ProtocolEntry extends AssociationRecord
{
    protected $table = 'assoc_protocol_entries';

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return [
            'registered_on' => 'date',
            'document_date' => 'date',
            'correspondent' => 'encrypted',
            'cancelled_at' => 'datetime',
            'fiscal_year' => 'integer',
            'sequential_number' => 'integer',
        ];
    }
}
