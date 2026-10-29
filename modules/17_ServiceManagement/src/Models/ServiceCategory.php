<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ServiceCategory extends BaseEnterpriseModel
{
    protected $table = 'esm_service_categories';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'translations' => 'array',
            'metadata' => 'array',
        ];
    }

    public function slaPolicies(): HasMany
    {
        return $this->hasMany(SLAPolicy::class, 'service_category_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'category_id');
    }

    public function problems(): HasMany
    {
        return $this->hasMany(Problem::class, 'category_id');
    }

    public function knowledgeArticles(): HasMany
    {
        return $this->hasMany(KnowledgeArticle::class, 'category_id');
    }
}
