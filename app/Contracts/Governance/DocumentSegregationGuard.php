<?php

declare(strict_types=1);

namespace App\Contracts\Governance;

use App\Enums\SegregatedDocument;
use App\Exceptions\SodDocumentConflictException;

/**
 * Reviews an approval or a release against the per-document segregation of duties rules: the person who creates (or
 * submits) a document is not the one who approves or releases it. Implemented by the governance module and bound only
 * when it is installed: without a binding every approval is allowed. Use App\Support\Governance\DocumentSegregation
 * from the modules so that the absence of the binding is handled in one place.
 */
interface DocumentSegregationGuard
{
    /**
     * Refuses the action when the actor is among the people who prepared the document and the company rule for this
     * kind of document is active and blocking; a warning rule records the conflict and lets the action through.
     *
     * @param  list<string>  $preparerIds  the users who created, submitted or calculated the document
     *
     * @throws SodDocumentConflictException
     */
    public function assertDifferentPeople(string $tenantId, SegregatedDocument $document, string $documentId, string $actorId, array $preparerIds): void;
}
