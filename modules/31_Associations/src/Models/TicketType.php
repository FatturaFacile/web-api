<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TicketType extends AssociationRecord
{
    protected $table = 'assoc_ticket_types';

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'quantity' => 'integer', 'is_active' => 'boolean'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }
}
