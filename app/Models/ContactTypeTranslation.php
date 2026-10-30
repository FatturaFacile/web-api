<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactTypeTranslation extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'contact_type_translations';

    protected $fillable = [
        'tenant_id',
        'contact_type_id',
        'locale',
        'name',
    ];

    /**
     * @return BelongsTo<ContactType, $this>
     */
    public function contactType(): BelongsTo
    {
        return $this->belongsTo(ContactType::class, 'contact_type_id');
    }
}
