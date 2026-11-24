<?php

declare(strict_types=1);

namespace App\Infrastructure\Procurement\Rfq\Services;

use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\RfqAttachment;
use App\Domain\Procurement\Rfq\Models\SupplierBid;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Handles secure storage, SHA-256 integrity hashing, and download streaming for RFQ and Bid attachments.
 */
final class RfqAttachmentStorageService
{
    public const string DEFAULT_DISK = 'local';

    public function storeRfqAttachment(
        RequestForQuotation $rfq,
        UploadedFile $file,
        bool $isVisibleToSuppliers = true,
    ): RfqAttachment {
        $disk = self::DEFAULT_DISK;
        $originalName = $this->sanitizeFilename($file->getClientOriginalName());
        $directory = "rfq-attachments/{$rfq->tenant_id}/{$rfq->id}";
        $storedName = Str::uuid7().'_'.$originalName;
        $path = $file->storeAs($directory, $storedName, $disk);
        $content = Storage::disk($disk)->get((string) $path) ?? '';

        return RfqAttachment::create([
            'tenant_id' => $rfq->tenant_id,
            'rfq_id' => $rfq->id,
            'file_name' => $originalName,
            'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
            'file_size_bytes' => (int) ($file->getSize() ?: strlen($content)),
            'storage_disk' => $disk,
            'storage_path' => (string) $path,
            'sha256_hash' => hash('sha256', $content),
            'is_visible_to_suppliers' => $isVisibleToSuppliers,
        ]);
    }

    /**
     * @return array{file_name: string, mime_type: string, size_bytes: int, disk: string, path: string, sha256: string}
     */
    public function storeBidAttachment(SupplierBid $bid, UploadedFile $file): array
    {
        $disk = self::DEFAULT_DISK;
        $originalName = $this->sanitizeFilename($file->getClientOriginalName());
        $directory = "rfq-bid-attachments/{$bid->tenant_id}/{$bid->rfq_id}/{$bid->id}";
        $storedName = Str::uuid7().'_'.$originalName;
        $path = $file->storeAs($directory, $storedName, $disk);
        $content = Storage::disk($disk)->get((string) $path) ?? '';

        return [
            'file_name' => $originalName,
            'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
            'size_bytes' => (int) ($file->getSize() ?: strlen($content)),
            'disk' => $disk,
            'path' => (string) $path,
            'sha256' => hash('sha256', $content),
        ];
    }

    public function deleteAttachment(RfqAttachment $attachment): void
    {
        if (Storage::disk($attachment->storage_disk)->exists($attachment->storage_path)) {
            Storage::disk($attachment->storage_disk)->delete($attachment->storage_path);
        }

        $attachment->delete();
    }

    public function streamRfqAttachment(RfqAttachment $attachment): StreamedResponse
    {
        if (! Storage::disk($attachment->storage_disk)->exists($attachment->storage_path)) {
            throw new NotFoundHttpException('Attachment file not found.');
        }

        return Storage::disk($attachment->storage_disk)->download(
            $attachment->storage_path,
            $attachment->file_name,
            [
                'Content-Type' => $attachment->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    private function sanitizeFilename(string $filename): string
    {
        $base = basename(str_replace('\\', '/', $filename));
        $cleaned = preg_replace('/[^A-Za-z0-9._-]/', '_', $base);

        return $cleaned !== null && $cleaned !== '' ? mb_substr($cleaned, 0, 200) : 'attachment.bin';
    }
}
