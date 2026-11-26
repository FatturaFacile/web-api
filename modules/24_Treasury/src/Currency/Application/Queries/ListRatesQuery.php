<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Queries;

use Modules\Treasury\Currency\Domain\Enums\RateType;

/**
 * The rates a company sees (official and its own), filtered, newest day first.
 */
final readonly class ListRatesQuery
{
    public function __construct(
        public string $tenantId,
        public ?string $base = null,
        public ?string $quote = null,
        public ?RateType $type = null,
        public ?string $from = null,
        public ?string $to = null,
        public bool $includeSuperseded = false,
        public int $perPage = 25,
    ) {}
}
