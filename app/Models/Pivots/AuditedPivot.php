<?php

declare(strict_types=1);

namespace App\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Base pivot for the many-to-many relations whose table carries the audit columns.
 *
 * A plain `belongsToMany` writes through the query builder, so `attach()`, `sync()` and `updateExistingPivot()` fire no
 * model events and `AuditStamper` never sees them. Declaring the relation with `->using(AuditedPivot::class)` makes
 * Laravel save every pivot row as a model: the stamper then fills `created_by` and `updated_by` from the authenticated
 * user, and leaves them null in the CLI, seeders and queues.
 *
 * It deliberately does not use SoftDeletes: `detach()` keeps removing the row, as it did before.
 */
class AuditedPivot extends Pivot
{
    /**
     * Rows of the memberships are created with explicit attributes (including the uuid), never guarded.
     *
     * @var array<int, string>
     */
    protected $guarded = [];
}
