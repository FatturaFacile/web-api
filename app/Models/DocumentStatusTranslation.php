<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentStatusTranslation extends Model
{
    use HasFactory;

    protected $table = 'document_status_translations';

    protected $fillable = [
        'document_status_id',
        'locale',
        'label',
        'description',
    ];

    /**
     * @return BelongsTo<DocumentStatus, $this>
     */
    public function documentStatus(): BelongsTo
    {
        return $this->belongsTo(DocumentStatus::class, 'document_status_id');
    }
}
