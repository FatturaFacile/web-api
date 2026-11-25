<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Modules\Documents\Services\DocumentLibrary;
use Modules\ExpenseManagement\Contracts\ReceiptDocumentStoreInterface;
use Modules\ExpenseManagement\Domain\ValueObjects\StoredReceipt;

/**
 * Receipts as documents of the document management module (Mod 007): the file is kept locally for the previewer and
 * registered in the library, so retention, search and legal hold apply to it. Enabled with expense_management.receipts.store = dms.
 */
final class DmsReceiptDocumentStore implements ReceiptDocumentStoreInterface
{
    public function __construct(private readonly LocalReceiptDocumentStore $local, private readonly DocumentLibrary $library) {}

    public function store(UploadedFile $file, string $tenantId, ?string $userId): StoredReceipt
    {
        $stored = $this->local->store($file, $tenantId, $userId);
        $user = $userId === null ? null : User::query()->find($userId);

        if ($user === null) {
            return $stored;
        }

        $document = $this->library->create(
            ['tenant_id' => $tenantId, 'title' => $file->getClientOriginalName(), 'document_type' => 'expense_receipt', 'metadata' => ['source' => 'expense_management']],
            $file,
            null,
            true,
            [],
            $user,
        );

        return new StoredReceipt($stored->disk, $stored->path, (string) $document->id);
    }
}
