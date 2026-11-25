<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Services;

use Illuminate\Http\UploadedFile;
use Modules\ExpenseManagement\Contracts\ReceiptDocumentStoreInterface;
use Modules\ExpenseManagement\Domain\ValueObjects\StoredReceipt;

/**
 * The safe default store: the receipt lives on the private local disk, under the tenant.
 */
final class LocalReceiptDocumentStore implements ReceiptDocumentStoreInterface
{
    public const string Disk = 'local';

    public function store(UploadedFile $file, string $tenantId, ?string $userId): StoredReceipt
    {
        $path = $file->store('expense-receipts/'.$tenantId, self::Disk);

        return new StoredReceipt(self::Disk, (string) $path);
    }
}
