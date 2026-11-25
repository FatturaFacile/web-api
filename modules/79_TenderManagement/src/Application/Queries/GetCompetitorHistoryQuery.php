<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Application\Queries;

/**
 * The competitor award history by contracting authority and CPV.
 */
final readonly class GetCompetitorHistoryQuery
{
    public function __construct(
        public ?string $search = null,
        public ?string $contractingAuthorityName = null,
        public ?string $cpvCode = null,
        public ?string $jurisdiction = null,
        public bool $winnersOnly = false,
        public int $perPage = 25,
    ) {}
}
