<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\GreenCare\Domain\Exceptions\GreenCareException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Before/after photos of the interventions on the private disk, one folder per company and stop.
 */
final class PhotoStore
{
    public function store(UploadedFile $file, string $tenantId, string $stopId): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');

        return (string) $file->storeAs('green-care/'.$tenantId.'/stops/'.$stopId, Str::uuid7().'.'.$extension, ['disk' => $this->disk()]);
    }

    /**
     * @throws GreenCareException file_not_found
     */
    public function download(?string $path, string $name): StreamedResponse
    {
        if ($path === null || $path === '' || ! Storage::disk($this->disk())->exists($path)) {
            throw GreenCareException::because('file_not_found', [], 404);
        }

        return Storage::disk($this->disk())->download($path, $name);
    }

    private function disk(): string
    {
        return (string) config('green_care.disk', 'local');
    }
}
