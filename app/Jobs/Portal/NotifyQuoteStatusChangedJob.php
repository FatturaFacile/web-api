<?php

declare(strict_types=1);

namespace App\Jobs\Portal;

use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotifyQuoteStatusChangedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly Quote $quote,
        public readonly string $newStatus,
        public readonly string $actorName,
        public readonly ?string $comments = null
    ) {}

    public function handle(): void
    {
        Log::info("Portal quote update: Quote {$this->quote->quote_number} was {$this->newStatus} by {$this->actorName}.", [
            'quote_id' => $this->quote->id,
            'tenant_id' => $this->quote->tenant_id,
            'comments' => $this->comments,
        ]);
    }
}
