<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TenantBackup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TenantBackup
 */
class TenantBackupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'status' => $this->status,
            'file_size_bytes' => $this->file_size_bytes,
            'error_message' => $this->error_message,
            'requested_by' => $this->user_id,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'download_url' => $this->isCompleted() ? route('system.backups.download', ['id' => $this->id]) : null,
        ];
    }
}
