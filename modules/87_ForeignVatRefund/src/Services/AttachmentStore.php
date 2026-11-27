<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\ForeignVatRefund\Domain\Exceptions\ForeignVatException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private storage of the scans of the original invoices and of the mandate documents. Files are never public: they are served only
 * through authenticated endpoints and are kept under a folder of the company.
 */
final class AttachmentStore
{
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('foreign_vat_refund.disk', 'local'));
    }

    public function store(UploadedFile $file, string $tenantId, string $folder): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $name = Str::uuid7().'.'.preg_replace('/[^a-z0-9]/', '', $extension);
        $path = $this->disk()->putFileAs('foreign-vat-refund/'.$tenantId.'/'.$folder, $file, $name);

        if (! is_string($path)) {
            throw ForeignVatException::because('upload_failed', [], 500);
        }

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '' && $this->disk()->exists($path)) {
            $this->disk()->delete($path);
        }
    }

    public function download(?string $path, string $filename): StreamedResponse
    {
        if ($path === null || $path === '' || ! $this->disk()->exists($path)) {
            throw ForeignVatException::because('file_not_found', [], 404);
        }

        return $this->disk()->download($path, $filename);
    }
}
