<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserPreferencesResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'theme_preference' => $this->theme_preference ?? 'system',
            'ui_density' => $this->ui_density ?? 'comfortable',
            'reduced_motion' => $this->reduced_motion,
        ];
    }
}
