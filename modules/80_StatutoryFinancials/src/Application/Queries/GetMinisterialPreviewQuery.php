<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Queries;

/**
 * Official ministerial-style (TEBENI) preview of a dossier: the statements in the layout of the deposit with the taxonomy element
 * of every line, the notes, the validation summary and an excerpt of the XBRL instance.
 */
final readonly class GetMinisterialPreviewQuery
{
    public function __construct(public string $dossierId) {}
}
