<?php

declare(strict_types=1);

namespace Modules\Integrations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Integrations\Models\WebhookEndpoint;

/**
 * @mixin WebhookEndpoint
 */
final class WebhookEndpointResource extends JsonResource
{
    /**
     * @param  string|null  $revealedSecret  the new signing secret, passed only by the calls that create it
     */
    public function __construct($resource, private readonly ?string $revealedSecret = null)
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
            'url' => $this->url,
            'description' => $this->description,
            'status' => $this->status,
            'events' => $this->events,
            'payload_version' => $this->payload_version,
            'secret_hint' => $this->secret_hint,
            'secret' => $this->revealedSecret,
            'previous_secret_expires_at' => $this->previous_secret_expires_at !== null && $this->previous_secret_expires_at->isFuture() ? $this->previous_secret_expires_at : null,
            'consecutive_failures' => $this->consecutive_failures,
            'disabled_at' => $this->disabled_at,
            'disabled_reason' => $this->disabled_reason,
            'last_success_at' => $this->last_success_at,
            'last_failure_at' => $this->last_failure_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
