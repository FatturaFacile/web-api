<?php

declare(strict_types=1);

namespace Modules\Procurement\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProcurementApiPaginationMeta',
    title: 'API Pagination Meta',
    description: 'Standard pagination metadata',
    required: ['current_page', 'per_page', 'total']
)]
class ProcurementApiPaginationMetaSchema
{
    #[OA\Property(example: 1)]
    public int $current_page;

    #[OA\Property(example: 25)]
    public int $per_page;

    #[OA\Property(example: 150)]
    public int $total;

    #[OA\Property(example: 6)]
    public int $last_page;
}
