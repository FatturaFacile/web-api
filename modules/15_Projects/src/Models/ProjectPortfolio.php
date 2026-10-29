<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ProjectPortfolio extends BaseEnterpriseModel
{
    protected $table = 'project_portfolios';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'portfolio_manager_user_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'portfolio_id');
    }
}
