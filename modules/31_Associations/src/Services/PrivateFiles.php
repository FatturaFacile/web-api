<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The files of the module live on the private disk, under the directory of the tenant that backups copy, with a random
 * name. They are only reachable through the authorized download endpoints.
 */
final class PrivateFiles
{
    /**
     * @return array{disk: string, path: string, original_name: string, mime_type: ?string, size: int}
     */
    public function store(UploadedFile $file, string $tenantId, string $folder): array
    {
        $disk = (string) config('filesystems.private_disk', config('filesystems.default', 'local'));
        $name = Str::random(40).'.'.strtolower($file->guessExtension() ?: 'bin');
        $path = "tenants/{$tenantId}/associations/{$folder}/{$name}";

        Storage::disk($disk)->put($path, $file->getContent());

        return [
            'disk' => $disk,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => $file->getMimeType(),
            'size' => (int) $file->getSize(),
        ];
    }

    public function download(?string $disk, ?string $path, ?string $name): StreamedResponse
    {
        if ($disk === null || $path === null || ! Storage::disk($disk)->exists($path)) {
            throw new NotFoundHttpException('File not found.');
        }

        return Storage::disk($disk)->download($path, $name);
    }
}
