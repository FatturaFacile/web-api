<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Domain\Strategy;

/**
 * How the XBRL instance of a standard is built: namespace, taxonomy entry point and the label of the taxonomy release.
 */
final readonly class XbrlProfile
{
    public function __construct(
        public string $prefix,
        public string $namespace,
        public string $entryPoint,
        public string $taxonomyVersion,
        public string $unit,
        public string $entityScheme,
    ) {}
}
