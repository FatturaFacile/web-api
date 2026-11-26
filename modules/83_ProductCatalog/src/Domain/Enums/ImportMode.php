<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Domain\Enums;

/**
 * Atomic: any invalid row stops the whole import. Partial: valid rows are saved and the invalid ones are reported.
 * Dry run: validates only and reports what would happen.
 */
enum ImportMode: string
{
    use EnumValues;

    case Atomic = 'atomic';
    case Partial = 'partial';
    case DryRun = 'dry_run';
}
