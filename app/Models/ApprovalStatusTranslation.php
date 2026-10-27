<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalStatusTranslation extends Model
{
    use HasFactory;

    protected $table = 'approval_status_translations';

    protected $fillable = [
        'approval_status_id',
        'locale',
        'name',
        'description',
    ];

    /**
     * @return BelongsTo<ApprovalStatus, $this>
     */
    public function approvalStatus(): BelongsTo
    {
        return $this->belongsTo(ApprovalStatus::class, 'approval_status_id');
    }
}
