<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Budgeting\Models\BudgetImport;

/**
 * The outcome of a budget file import with its row by row errors.
 *
 * @mixin BudgetImport
 */
final class ImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version_id' => $this->version_id,
            'file_name' => $this->file_name,
            'format' => $this->format,
            'mode' => $this->mode,
            'status' => $this->status,
            'total_rows' => $this->total_rows,
            'imported_rows' => $this->imported_rows,
            'error_rows' => $this->error_rows,
            'errors' => $this->errors ?? [],
            'user_id' => $this->user_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
