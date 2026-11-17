<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Contact person of a customer registry entry, classified by business role (administration, purchasing, logistics...).
 */
class ContactPerson extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    public const ROLES = ['administration', 'purchasing', 'logistics', 'technical', 'management', 'sales', 'other'];

    protected $table = 'contact_persons';

    protected $fillable = [
        'tenant_id',
        'contact_id',
        'first_name',
        'last_name',
        'job_title',
        'role',
        'email',
        'phone',
        'mobile',
        'language',
        'is_primary',
        'notes',
    ];

    protected $attributes = [
        'role' => 'other',
        'is_primary' => false,
    ];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value !== null ? strtolower(trim($value)) : null,
        );
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
