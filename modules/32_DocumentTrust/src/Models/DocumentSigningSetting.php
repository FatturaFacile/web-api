<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\DocumentSigningSettingFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(DocumentSigningSettingFactory::class)]
class DocumentSigningSetting extends Model
{
    /** @use HasFactory<DocumentSigningSettingFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'driver', 'certificate', 'private_key', 'passphrase', 'certificate_expires_at'];

    /**
     * @var list<string>
     */
    protected $hidden = ['certificate', 'private_key', 'passphrase'];

    protected function casts(): array
    {
        return ['certificate' => 'encrypted', 'private_key' => 'encrypted', 'passphrase' => 'encrypted', 'certificate_expires_at' => 'datetime'];
    }
}
