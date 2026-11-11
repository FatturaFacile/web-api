<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Services;

use Modules\DocumentTrust\Models\PreservationDisposal;
use Modules\DocumentTrust\Models\PreservationItem;
use Modules\DocumentTrust\Models\PreservationPackage;

/**
 * Tells whether a document of the library is part of a closed preservation package. The document library (module 25)
 * asks it before it changes, versions, deletes or purges a document, so a preserved document stays as it was.
 */
final class PreservationLock
{
    /**
     * Whether the document is in a closed package.
     */
    public static function documentIsPreserved(string $documentId): bool
    {
        return PreservationItem::withoutGlobalScopes()
            ->where('source_type', 'dms_document')
            ->where('source_id', $documentId)
            ->whereIn('package_id', PreservationPackage::withoutGlobalScopes()->select('id')->where('status', PreservationPackage::Closed)->whereNotIn('id', PreservationDisposal::withoutGlobalScopes()->select('package_id')))
            ->exists();
    }
}
