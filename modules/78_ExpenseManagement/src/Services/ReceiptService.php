<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\ExpenseManagement\Contracts\ReceiptDocumentStoreInterface;
use Modules\ExpenseManagement\Contracts\ReceiptOcrProviderInterface;
use Modules\ExpenseManagement\Exceptions\ExpenseException;
use Modules\ExpenseManagement\Models\Receipt;

/**
 * Receipt acquisition: hash, store, OCR and duplicate check. Uploading the same file twice reuses the stored receipt.
 */
final class ReceiptService
{
    public function __construct(
        private readonly ReceiptDocumentStoreInterface $store,
        private readonly ReceiptOcrProviderInterface $ocr,
        private readonly DuplicateDetector $duplicates,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function scan(UploadedFile $file, ?string $clientText, string $tenantId, ?string $userId): array
    {
        $contents = (string) file_get_contents((string) $file->getRealPath());
        $sha256 = hash('sha256', $contents);
        $mime = (string) ($file->getMimeType() ?? $file->getClientMimeType());
        $ocr = $this->ocr->extract($contents, $mime, $file->getClientOriginalName(), $clientText);

        $receipt = Receipt::query()->where('sha256', $sha256)->first();

        if ($receipt === null) {
            $stored = $this->store->store($file, $tenantId, $userId);
            $receipt = Receipt::create([
                'tenant_id' => $tenantId,
                'document_id' => $stored->documentId,
                'sha256' => $sha256,
                'disk' => $stored->disk,
                'path' => $stored->path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $mime,
                'size_bytes' => (int) $file->getSize(),
                'created_by' => $userId,
            ]);
        }

        $receipt->update(['ocr_provider' => $ocr->provider, 'ocr_confidence' => number_format($ocr->confidence, 4, '.', ''), 'ocr_payload' => $ocr->toArray()]);

        $warnings = [];

        if ($ocr->merchantName !== null && $ocr->expenseDate !== null && $ocr->totalAmount !== null) {
            $warnings = $this->duplicates->find($sha256, $ocr->merchantName, Carbon::parse($ocr->expenseDate), $ocr->totalAmount);
        } else {
            $warnings = $this->duplicates->find($sha256, '', now(), '0.00');
        }

        return [
            'receipt_id' => (string) $receipt->id,
            'sha256' => $sha256,
            'mime_type' => $mime,
            'size_bytes' => (int) $file->getSize(),
            'file_name' => $file->getClientOriginalName(),
            'ocr' => $ocr->toArray(),
            'duplicate_warnings' => $warnings,
        ];
    }

    public function read(Receipt $receipt): string
    {
        $disk = Storage::disk($receipt->disk);

        if (! $disk->exists($receipt->path)) {
            throw new ExpenseException('receipt_not_found', 404);
        }

        return (string) $disk->get($receipt->path);
    }
}
