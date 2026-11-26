<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The checklist of a password against a policy, or the rules of a policy, as built by PasswordPolicyController.
 *
 * @property array<string, mixed> $resource
 */
final class PasswordPolicyCheckResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
