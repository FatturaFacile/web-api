<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Intercompany\Models\ConsolidationPackage;

/**
 * @mixin ConsolidationPackage
 */
final class PackageResource extends JsonResource
{
    public function __construct($resource, private readonly bool $withPayload = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = $this->payload ?? [];

        return [
            'id' => (string) $this->id,
            'run_id' => (string) $this->run_id,
            'standard' => $this->standard,
            'checksum' => $this->checksum,
            'generated_at' => $this->generated_at->toIso8601String(),
            'sections' => array_values(array_diff(array_keys($payload), ['meta'])),
            'payload' => $this->withPayload ? $payload : null,
        ];
    }
}
