<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactCategoryTranslation extends Model
{
    use HasFactory;

    protected $table = 'contact_category_translations';

    protected $fillable = [
        'contact_category_id',
        'locale',
        'name',
    ];

    /**
     * @return BelongsTo<ContactCategory, $this>
     */
    public function contactCategory(): BelongsTo
    {
        return $this->belongsTo(ContactCategory::class, 'contact_category_id');
    }
}
