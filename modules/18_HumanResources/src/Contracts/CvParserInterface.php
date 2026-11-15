<?php

declare(strict_types=1);

namespace Modules\HumanResources\Contracts;

use Modules\HumanResources\DTOs\ParsedCvDTO;

/**
 * Extracts a structured profile from a CV. Stands in for the AI CV parsing of Mod 092; implementations must never
 * throw on unreadable documents but report `failed` instead.
 */
interface CvParserInterface
{
    public function parse(string $contents, string $mimeType, string $fileName): ParsedCvDTO;
}
