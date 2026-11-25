<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

/**
 * Verifies a stored PDF/A document against its conformance level and records the outcome on the artifact.
 */
final readonly class VerifyPdfACommand
{
    public function __construct(public string $dossierId, public string $artifactId) {}
}
