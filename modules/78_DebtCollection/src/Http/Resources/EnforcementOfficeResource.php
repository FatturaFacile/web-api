<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DebtCollection\Models\EnforcementOffice;

/**
 * @mixin EnforcementOffice
 */
final class EnforcementOfficeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'postal_code_from' => $this->postal_code_from,
            'postal_code_to' => $this->postal_code_to,
            'address' => $this->address,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'canton' => $this->canton,
            'email' => $this->email,
        ];
    }
}
