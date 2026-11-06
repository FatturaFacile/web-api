<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CommunicationChannel extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'channel', 'driver', 'is_enabled', 'credentials'];

    /**
     * @var list<string>
     */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'credentials' => 'encrypted:array'];
    }
}
