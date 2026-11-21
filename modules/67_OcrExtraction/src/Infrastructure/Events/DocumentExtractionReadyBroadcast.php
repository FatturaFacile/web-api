<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Real-time notice (Laravel Reverb / WebSockets) that a document is ready for review, on the private channel of the
 * tenant. It carries ids and the headline only, never the extracted content.
 */
final class DocumentExtractionReadyBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $jobId,
        public readonly string $status,
        public readonly string $documentCategory,
        public readonly float $confidence,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->tenantId}.ocr-inbox")];
    }

    public function broadcastAs(): string
    {
        return 'document-extraction.ready';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['job_id' => $this->jobId, 'status' => $this->status, 'document_category' => $this->documentCategory, 'confidence' => $this->confidence];
    }
}
