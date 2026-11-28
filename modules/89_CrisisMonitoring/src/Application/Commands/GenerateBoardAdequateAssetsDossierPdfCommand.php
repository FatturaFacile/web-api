<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Application\Commands;

use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * Generates the quarterly board and statutory auditors dossier on the adequacy of the organisational, administrative and accounting
 * arrangements (Art. 2086 c.c.) as a PDF in the requested locale.
 */
final readonly class GenerateBoardAdequateAssetsDossierPdfCommand
{
    public function __construct(
        public Tenant $tenant,
        public string $locale,
        public ?CarbonImmutable $asOf = null,
    ) {}
}
