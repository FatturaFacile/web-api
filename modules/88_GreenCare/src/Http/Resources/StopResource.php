<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GreenCare\Models\CrewRouteStop;

/**
 * Photos are never exposed as storage paths: the client gets the authenticated endpoint of each photo.
 *
 * @mixin CrewRouteStop
 */
final class StopResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $site = $this->site;

        return [
            'id' => $this->id,
            'route_schedule_id' => $this->route_schedule_id,
            'site_id' => $this->site_id,
            'site_name' => $site->name ?? null,
            'site_address' => $site->address ?? null,
            'latitude' => $site?->latitude === null ? null : (float) $site->latitude,
            'longitude' => $site?->longitude === null ? null : (float) $site->longitude,
            'contract_id' => $this->contract_id,
            'contract_number' => $this->contract->contract_number ?? null,
            'stop_order' => $this->stop_order,
            'planned_activities' => $this->planned_activities ?? [],
            'estimated_duration_minutes' => $this->estimated_duration_minutes,
            'actual_checkin_at' => $this->actual_checkin_at?->toIso8601String(),
            'actual_checkout_at' => $this->actual_checkout_at?->toIso8601String(),
            'checkin_gps' => $this->checkin_gps,
            'checkout_gps' => $this->checkout_gps,
            'crew_hours_logged' => $this->crew_hours_logged ?? ['members' => [], 'total' => 0],
            'machinery_hours_logged' => $this->machinery_hours_logged ?? [],
            'materials_and_plants_used' => $this->materials_and_plants_used ?? [],
            'before_photos' => $this->photoUrls('before', $this->before_photos),
            'after_photos' => $this->photoUrls('after', $this->after_photos),
            'counts_toward_contract_passage' => $this->counts_toward_contract_passage,
            'is_extra_passage' => $this->is_extra_passage,
            'billing_status' => $this->billing_status->value,
            'invoice_id' => $this->invoice_id,
            'passage_number' => $this->passage_number,
            'passage_included' => $this->contract?->included_passages_count,
        ];
    }

    /**
     * @param  list<string>|null  $paths
     * @return list<string>
     */
    private function photoUrls(string $type, ?array $paths): array
    {
        return array_map(
            fn (int $index): string => '/api/v1/green-care/route-stops/'.$this->id.'/photos/'.$type.'/'.$index,
            array_keys(array_values($paths ?? [])),
        );
    }
}
