<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The 207 body of the MES Core relay endpoint: one `{id, status, message}` per message, in order.
 */
final class MesEventsResultResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var list<array{id: string|null, status: string, message: string|null}> $results */
        $results = $this->resource;

        return ['results' => $results];
    }
}
