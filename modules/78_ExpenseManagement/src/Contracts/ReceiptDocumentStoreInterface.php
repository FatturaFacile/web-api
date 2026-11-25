<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Contracts;

use Illuminate\Http\UploadedFile;
use Modules\ExpenseManagement\Domain\ValueObjects\StoredReceipt;

/**
 * Where receipt files live: the local disk, or the document management module (Mod 007) which also keeps the document.
 */
interface ReceiptDocumentStoreInterface
{
    public function store(UploadedFile $file, string $tenantId, ?string $userId): StoredReceipt;
}
