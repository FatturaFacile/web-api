<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContactType extends Model
{
    use BelongsToTenant, HasFactory, HasTranslations, HasUuids;

    protected $table = 'contact_types';

    protected $fillable = [
        'tenant_id',
        'code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ContactTypeTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ContactTypeTranslation::class, 'contact_type_id');
    }

    protected function getTranslationModelClass(): string
    {
        return ContactTypeTranslation::class;
    }

    protected function getTranslationForeignKey(): string
    {
        return 'contact_type_id';
    }
}
