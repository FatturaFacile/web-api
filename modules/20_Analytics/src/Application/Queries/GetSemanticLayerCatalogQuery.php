<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Queries;

use App\Models\User;

/**
 * Asks for the datasets, dimensions, measures, operators and formatting options a user may use.
 */
final readonly class GetSemanticLayerCatalogQuery
{
    public function __construct(public User $user) {}
}
