<?php

declare(strict_types=1);

namespace Modules\Quoting\Domain\Contracts;

interface QuoteDocumentRenderer
{
    /**
     * Renders an HTML document to an archival PDF.
     *
     * @param  array<string, string>  $metadata  title, author, subject, keywords
     * @return array{bytes: string, pdfa_compliant: bool}
     */
    public function render(string $html, array $metadata): array;
}
