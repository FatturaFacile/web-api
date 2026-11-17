<?php

declare(strict_types=1);

namespace App\Models\Access;

use App\Models\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A group of members of one company, optionally with a custom role that its new members receive.
 *
 * @property string $id
 * @property string $name
 * @property string|null $description
 * @property string|null $custom_role_id
 */
class AccessTeam extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'access_teams';

    protected $fillable = ['tenant_id', 'name', 'description', 'custom_role_id'];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'access_team_members', 'team_id', 'user_id');
    }
}
