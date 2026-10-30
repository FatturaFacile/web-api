<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactCategoryTranslation extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'contact_category_translations';

    protected $fillable = [
        'tenant_id',
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
