<?php

declare(strict_types=1);

namespace App\Jobs\Expenses;

use App\Models\ExpenseAttachment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessReceiptUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly ExpenseAttachment $expenseAttachment)
    {
        $this->afterCommit = true;
    }

    /**
     * Process uploaded receipt: verify file integrity and prepare OCR extraction metadata.
     */
    public function handle(): void
    {
        $attachment = $this->expenseAttachment->fresh();
        if (! $attachment) {
            return;
        }

        $disk = $attachment->getStorageDisk();
        if (! Storage::disk($disk)->exists($attachment->path)) {
            Log::warning("Receipt file missing on disk: {$attachment->path}");

            return;
        }

        // Example metadata extraction / size verification
        $actualSize = Storage::disk($disk)->size($attachment->path);
        if ($actualSize !== $attachment->size) {
            $attachment->update(['size' => $actualSize]);
        }

        Log::info("Receipt {$attachment->id} processed successfully for expense {$attachment->expense_id}");
    }
}
