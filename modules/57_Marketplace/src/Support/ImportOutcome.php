<?php

declare(strict_types=1);

namespace Modules\Marketplace\Support;

/**
 * What the import of the pulled records of a resource did: how many records came in, how many failed and what happened
 * to the others, and the cursor to resume from.
 */
final readonly class ImportOutcome
{
    public function __construct(
        public int $processed,
        public int $failed,
        public int $created,
        public int $updated,
        public int $unchanged,
        public ?string $cursor,
    ) {}
}
