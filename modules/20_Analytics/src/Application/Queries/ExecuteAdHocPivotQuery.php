<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Queries;

use App\Models\User;

/**
 * Runs a report definition that has not been saved (or is being edited) as a pivot. `$definition` carries `dataset`,
 * `spec`, `pivot`, `calculated_fields`, `conditional_formatting` and `chart_config`, exactly as a saved report stores them.
 */
final readonly class ExecuteAdHocPivotQuery
{
    /**
     * @param  array<string, mixed>  $definition
     */
    public function __construct(public User $user, public array $definition) {}
}
