<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\DocumentEvent;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class DocumentEventRecorder
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Append an entry to a document's audit timeline.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(Model $document, string $event, array $payload = []): DocumentEvent
    {
        return DocumentEvent::create([
            'tenant_id' => $this->tenantContext->getTenantId(),
            'eventable_type' => $document->getMorphClass(),
            'eventable_id' => $document->getKey(),
            'user_id' => Auth::id(),
            'event' => $event,
            'payload' => $payload,
        ]);
    }
}
