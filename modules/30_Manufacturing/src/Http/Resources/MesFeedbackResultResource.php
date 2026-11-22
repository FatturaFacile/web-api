<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The multi-status answer of the feedback endpoint: one result per event, in the order of the batch.
 */
final class MesFeedbackResultResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var list<array<string, mixed>> $results */
        $results = $this->resource;

        return ['results' => $results];
    }
}
