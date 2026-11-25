<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ElectronicSignature\Models\SignatureRecipient;

/**
 * A recipient as the staff of the company sees it: no token, no code, no identity data.
 *
 * @mixin SignatureRecipient
 */
final class RecipientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'signing_order' => $this->signing_order,
            'role' => $this->role->value,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'auth_method' => $this->auth_method->value,
            'signature_box' => $this->signature_box,
            'status' => $this->status->value,
            'notified_at' => $this->notified_at?->toIso8601String(),
            'viewed_at' => $this->viewed_at?->toIso8601String(),
            'signed_at' => $this->signed_at?->toIso8601String(),
            'declined_at' => $this->declined_at?->toIso8601String(),
            'decline_reason' => $this->decline_reason,
            'signature_method' => $this->signature_method,
            'certificate_serial' => $this->certificate_serial,
        ];
    }
}
