<?php

declare(strict_types=1);

namespace Modules\Visitors\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Visitors\Models\VisitorKiosk;

/**
 * A reception kiosk. The secret appears only in the answer that creates or rotates it.
 *
 * @mixin VisitorKiosk
 */
final class KioskResource extends JsonResource
{
    /**
     * Inject the collaborators.
     */
    public function __construct(VisitorKiosk $resource, private readonly ?string $secret = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'device_key' => $this->device_key,
            'is_active' => $this->is_active,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'secret' => $this->when($this->secret !== null, $this->secret),
        ];
    }
}
