<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExpenseAttachment extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'expense_attachments';

    protected $fillable = [
        'expense_id',
        'attachment_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'is_receipt',
        'ocr_raw_text',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'is_receipt' => 'boolean',
        ];
    }

    /**
     * Associated expense.
     *
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'expense_id');
    }

    /**
     * Associated general attachment if linked.
     *
     * @return BelongsTo<Attachment, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'attachment_id');
    }

    /**
     * Resolve the storage disk for this expense receipt.
     */
    public function getStorageDisk(): string
    {
        return $this->disk ?? (string) config('filesystems.private_disk', config('filesystems.default', 'local'));
    }
}
