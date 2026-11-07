<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Contracts;

use Modules\DocumentTrust\Exceptions\DocumentTrustException;

interface PreservationDriver
{
    public function name(): string;

    /**
     * @return array{status: string, reference: ?string}
     *
     * @throws DocumentTrustException when the document cannot be preserved
     */
    public function preserve(string $documentName, string $content): array;
}
