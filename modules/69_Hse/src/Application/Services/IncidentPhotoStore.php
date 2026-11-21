<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Hse\Domain\Exceptions\HseException;
use Modules\Hse\Infrastructure\Models\IncidentPhoto;

/**
 * Stores the photos of an incident. The phone sends them inline (base64) because its offline queue carries JSON only;
 * the content is checked to really be a JPEG or PNG within the size limit, never trusted from the declared type.
 */
final class IncidentPhotoStore
{
    public const MaxPhotos = 3;

    public const MaxBytes = 3_145_728;

    private const Extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

    /**
     * Throws when a photo is not a valid image: the incident is not stored with a photo that cannot be read back.
     *
     * @param  list<string>  $photos  base64 content, with or without a data: prefix
     * @return list<array{binary: string, mime: string}>
     */
    public function decode(array $photos): array
    {
        if (count($photos) > self::MaxPhotos) {
            throw new HseException('An incident takes at most '.self::MaxPhotos.' photos.');
        }

        $decoded = [];

        foreach ($photos as $photo) {
            $binary = base64_decode((string) preg_replace('/^data:[^;]+;base64,/', '', $photo), true);

            if ($binary === false || $binary === '' || strlen($binary) > self::MaxBytes) {
                throw new HseException('A photo is not a valid image or exceeds '.(self::MaxBytes / 1_048_576).' MB.');
            }

            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary);

            if (! isset(self::Extensions[$mime])) {
                throw new HseException('Only JPEG and PNG photos are accepted.');
            }

            $decoded[] = ['binary' => $binary, 'mime' => $mime];
        }

        return $decoded;
    }

    /**
     * @param  list<array{binary: string, mime: string}>  $photos  the output of decode()
     */
    public function attach(string $tenantId, string $incidentId, array $photos, ?string $userId): void
    {
        if ($photos === []) {
            return;
        }

        $disk = Storage::disk((string) config('filesystems.private_disk', config('filesystems.default', 'local')));
        $written = [];

        try {
            $rows = [];

            foreach ($photos as $photo) {
                $path = "tenants/{$tenantId}/hse/incidents/{$incidentId}/".Str::uuid().'.'.self::Extensions[$photo['mime']];
                $disk->put($path, $photo['binary']);
                $written[] = $path;
                $rows[] = ['file_path' => $path, 'mime_type' => $photo['mime'], 'size_bytes' => strlen($photo['binary'])];
            }

            DB::transaction(function () use ($rows, $tenantId, $incidentId, $userId): void {
                foreach ($rows as $row) {
                    IncidentPhoto::create([...$row, 'tenant_id' => $tenantId, 'incident_report_id' => $incidentId, 'created_by' => $userId]);
                }
            });
        } catch (\Throwable $failure) {
            $disk->delete($written);

            throw $failure;
        }
    }
}
