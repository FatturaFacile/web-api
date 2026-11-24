<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Response headers for a file a user uploaded. Only passive formats (PDF and raster images) may be shown inline;
 * anything else (HTML, SVG, XML, scripts...) is always sent as a download, so an uploaded file can never run in the
 * application origin. The file name is encoded so it cannot break out of the header.
 */
final class StoredFileHeaders
{
    /**
     * @var list<string>
     */
    public const array INLINE_MIME_TYPES = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'];

    /**
     * @return array{Content-Type: string, Content-Disposition: string, X-Content-Type-Options: string}
     */
    public static function for(?string $mimeType, string $fileName, bool $wantsInline): array
    {
        $mimeType = self::normalize($mimeType);
        $inline = $wantsInline && in_array($mimeType, self::INLINE_MIME_TYPES, true);

        return [
            'Content-Type' => $inline ? $mimeType : self::downloadType($mimeType),
            'Content-Disposition' => self::disposition($inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT, $fileName),
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    /**
     * A `type/subtype` in lower case without parameters, or `application/octet-stream` when the value is not one.
     */
    public static function normalize(?string $mimeType): string
    {
        $mimeType = strtolower(trim(explode(';', (string) $mimeType)[0]));

        return preg_match('#^[a-z0-9][a-z0-9!\#$&^_.+-]{0,126}/[a-z0-9][a-z0-9!\#$&^_.+-]{0,126}$#', $mimeType) === 1 ? $mimeType : 'application/octet-stream';
    }

    /**
     * Active formats are downloaded as opaque bytes; passive ones keep their type so the browser names the file well.
     */
    private static function downloadType(string $mimeType): string
    {
        $subtype = substr($mimeType, strpos($mimeType, '/') + 1);
        $active = str_starts_with($mimeType, 'text/') || str_contains($subtype, 'html') || $subtype === 'xml' || str_ends_with($subtype, '+xml')
            || str_contains($subtype, 'javascript') || str_contains($subtype, 'ecmascript') || str_contains($subtype, 'svg');

        return $active ? 'application/octet-stream' : $mimeType;
    }

    private static function disposition(string $type, string $fileName): string
    {
        $fileName = str_replace(['/', '\\', "\0", "\r", "\n"], '_', $fileName);
        $fileName = $fileName === '' ? 'file' : $fileName;
        $fallback = Str::ascii($fileName);
        $fallback = (string) preg_replace('/[^\x20-\x7e]|["%]/', '_', $fallback);

        return HeaderUtils::makeDisposition($type, $fileName, $fallback === '' ? 'file' : $fallback);
    }
}
