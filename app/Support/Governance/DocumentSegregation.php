<?php

declare(strict_types=1);

namespace App\Support\Governance;

use App\Contracts\Governance\DocumentSegregationGuard;
use App\Enums\SegregatedDocument;
use App\Exceptions\SodDocumentConflictException;

/**
 * Entry point of the modules to the per-document segregation of duties guard: a no-op while the governance module is
 * not installed.
 */
final class DocumentSegregation
{
    /**
     * @param  list<string|null>  $preparerIds
     *
     * @throws SodDocumentConflictException
     */
    public static function assertDifferentPeople(?string $tenantId, SegregatedDocument $document, string $documentId, string $actorId, array $preparerIds): void
    {
        if ($tenantId === null || $tenantId === '' || ! app()->bound(DocumentSegregationGuard::class)) {
            return;
        }

        app(DocumentSegregationGuard::class)->assertDifferentPeople(
            $tenantId,
            $document,
            $documentId,
            $actorId,
            array_values(array_unique(array_map('strval', array_filter($preparerIds)))),
        );
    }
}
