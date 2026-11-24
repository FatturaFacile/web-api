<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Concerns\HasImmutableAuditTrail;
use App\Domain\Audit\Contracts\AuditableEntityInterface;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankAccount extends Model implements AuditableEntityInterface
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;
    use HasImmutableAuditTrail;

    /** @var list<string> */
    protected array $auditMask = ['iban', 'qr_iban'];

    protected bool $auditTrackReads = true;

    protected $table = 'bank_accounts';

    protected $fillable = [
        'tenant_id',
        'bank_name',
        'iban',
        'qr_iban',
        'bic_swift',
        'currency',
        'current_balance',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'current_balance' => 'decimal:2',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return HasMany<BankTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class, 'bank_account_id');
    }
}
