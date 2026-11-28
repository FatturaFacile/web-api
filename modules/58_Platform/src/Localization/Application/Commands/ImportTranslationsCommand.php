<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Commands;

/**
 * Imports a translation sheet as tenant overrides. With dryRun the sheet is only analysed (diff preview).
 */
final readonly class ImportTranslationsCommand
{
    public function __construct(
        public string $tenantId,
        public string $path,
        public string $extension,
        public bool $dryRun,
    ) {}
}
