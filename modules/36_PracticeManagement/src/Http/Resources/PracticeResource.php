<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A closing or a fee note as the API shows it: its attributes, decimals as strings.
 */
final class PracticeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
