<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Platform\Localization\Application\Data\ImportResult;

/**
 * The diff preview or the outcome of a translation import. The error codes of the rows are translated into the language
 * of the request.
 *
 * @property ImportResult $resource
 */
final class ImportResultResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource->toArray();
        $data['rows'] = array_map(static function (array $row): array {
            $row['errors'] = array_map(static fn (string $code): string => __('localization.import.errors.'.$code), $row['errors']);

            return $row;
        }, $data['rows']);

        return $data;
    }
}
