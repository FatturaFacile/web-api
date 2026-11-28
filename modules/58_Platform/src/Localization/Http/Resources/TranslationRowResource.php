<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One key of the translation editor with its effective value and state flags.
 *
 * @property array<string, mixed> $resource
 */
final class TranslationRowResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->resource['key'],
            'group' => $this->resource['group'],
            'locale' => $this->resource['locale'],
            'value' => $this->resource['value'],
            'system_value' => $this->resource['system_value'],
            'is_overridden' => (bool) $this->resource['is_overridden'],
            'is_locked' => (bool) $this->resource['is_locked'],
            'is_deprecated' => (bool) $this->resource['is_deprecated'],
            'is_missing' => (bool) $this->resource['is_missing'],
        ];
    }
}
