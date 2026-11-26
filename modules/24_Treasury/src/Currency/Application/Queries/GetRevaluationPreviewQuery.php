<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Queries;

final readonly class GetRevaluationPreviewQuery
{
    public function __construct(public string $tenantId, public string $revaluationId) {}
}
