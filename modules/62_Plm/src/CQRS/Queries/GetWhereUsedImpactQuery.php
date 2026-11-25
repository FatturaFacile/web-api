<?php

declare(strict_types=1);

namespace Modules\Plm\CQRS\Queries;

/**
 * Multi-level Where-Used implosion returning all parent assemblies, open Purchase Orders,
 * open Production Orders, and Warehouse Stock / Lots impacted by a component or revision change.
 */
final readonly class GetWhereUsedImpactQuery
{
    public function __construct(
        public string $tenantId,
        public string $mfgItemId,
        public ?string $date = null,
    ) {}
}
