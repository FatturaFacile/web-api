<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A projection reconstructed at a bi-temporal coordinate (the event count, checkpoint use and timing travel in the response meta).
 */
final class StateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $state */
        $state = $this->resource;

        return [
            'domain' => $state['domain'],
            'coordinate' => $state['coordinate'],
            'rows' => $state['rows'],
            'totals' => $state['totals'],
        ];
    }
}
