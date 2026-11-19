<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Queries;

use App\Models\User;

/**
 * Runs the specification of a report (dataset and `spec`) as a flat, paginated result with the permissions of the caller.
 */
final readonly class RunReportQuery
{
    /**
     * @param  array<string, mixed>  $spec
     */
    public function __construct(public User $user, public string $dataset, public array $spec, public int $page = 1, public int $perPage = 50, public string $errorPrefix = '') {}
}
