<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CommunicationChannelFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model of the communication channel (tenant scoped where it has a tenant_id).
 */
#[UseFactory(CommunicationChannelFactory::class)]
class CommunicationChannel extends Model
{
    /** @use HasFactory<CommunicationChannelFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

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
