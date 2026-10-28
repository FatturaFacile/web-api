<?php

declare(strict_types=1);

namespace App\Events\Portal;

use App\Models\PortalAccessToken;
use App\Models\Quote;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QuoteApprovedByCustomer
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Quote $quote,
        public readonly PortalAccessToken $token,
        public readonly string $signerName,
        public readonly ?string $notes = null
    ) {}
}
