<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Platform\Localization\Application\Data\DictionaryData;

/**
 * @property DictionaryData $resource
 */
final class DictionaryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{locale: string, version_hash: string, entries: object}
     */
    public function toArray(Request $request): array
    {
        return [
            'locale' => $this->resource->locale,
            'version_hash' => $this->resource->versionHash,
            // An object even when empty: clients read it as a key/value map
            'entries' => (object) $this->resource->entries,
        ];
    }
}
