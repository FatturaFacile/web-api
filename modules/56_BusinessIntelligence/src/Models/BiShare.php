<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Models;

use App\Models\User;
use Database\Factories\BiShareFactory;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dashboard or a saved query shared with one member of the company, for viewing or for editing.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $resource_type
 * @property string $resource_id
 * @property string $user_id
 * @property string $permission
 * @property string|null $created_by
 */
#[UseFactory(BiShareFactory::class)]
final class BiShare extends BaseEnterpriseModel
{
    public const string Dashboard = 'dashboard';

    public const string SavedQuery = 'saved_query';

    public const string View = 'view';

    public const string Edit = 'edit';

    /** @var list<string> */
    public const array ResourceTypes = [self::Dashboard, self::SavedQuery];

    /** @var list<string> */
    public const array Permissions = [self::View, self::Edit];

    protected $table = 'bi_shares';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
