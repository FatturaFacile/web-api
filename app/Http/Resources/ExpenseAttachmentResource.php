<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ExpenseAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExpenseAttachment
 */
class ExpenseAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expense_id' => $this->expense_id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'is_receipt' => $this->is_receipt,
            'ocr_raw_text' => $this->ocr_raw_text,
            'download_url' => route('expenses.attachments.download', [
                'id' => $this->expense_id,
                'attachmentId' => $this->id,
            ]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
