<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Models;

use App\Domain\Audit\Concerns\HasImmutableAuditTrail;
use App\Domain\Audit\Contracts\AuditableEntityInterface;
use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\GeneralLedger\Models\ChartOfAccount;

/**
 * Custom chart-of-accounts transcoding rule mapping a client account to the external studio's account.
 */
final class FiduciaryAccountTranscoding extends BaseEnterpriseModel implements AuditableEntityInterface
{
    use BelongsToCompany;
    use HasImmutableAuditTrail;

    protected $table = 'fiduciary_account_transcodings';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'source_account_id');
    }

    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'practitioner_user_id');
    }
}
