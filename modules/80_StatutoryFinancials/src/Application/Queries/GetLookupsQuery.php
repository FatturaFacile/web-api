<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Queries;

/**
 * Seeded reference data for the client: standards, rounding modes, statuses, fiscal years, seeded wording (labels, narrative templates,
 * standard descriptions) and the ratio definitions, all in the requested locale.
 */
final readonly class GetLookupsQuery
{
    public function __construct(public string $locale) {}
}
