<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Mes\Queries\PhaseViewFactory;
use Modules\Manufacturing\Models\MfgMesPhase;

/**
 * A phase of the operator board in the shape of the board rows: state, allowed actions, times and counters.
 *
 * @mixin MfgMesPhase
 */
final class MesPhaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MfgMesPhase $phase */
        $phase = $this->resource;

        return app(PhaseViewFactory::class)->forPhases(collect([$phase]), CarbonImmutable::now())[0];
    }
}
