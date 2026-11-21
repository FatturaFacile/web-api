<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Queries;

use Carbon\CarbonImmutable;
use Modules\Hse\Domain\Enums\Jurisdiction;
use Modules\Hse\Domain\Enums\TrafficLight;

final readonly class GetHseComplianceMatrixQuery
{
    public function __construct(
        public string $tenantId,
        public CarbonImmutable $asOf,
        public int $page = 1,
        public int $perPage = 25,
        public ?string $departmentId = null,
        public ?string $workCenterId = null,
        public ?string $constructionSiteId = null,
        public ?TrafficLight $status = null,
        public ?Jurisdiction $jurisdiction = null,
    ) {}
}
