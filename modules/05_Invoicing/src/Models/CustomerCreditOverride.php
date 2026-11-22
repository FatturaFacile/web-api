<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Authorised exception to a credit block of a customer: who allowed it, why and until when.
 *
 * @property string $id
 * @property string $customer_id
 * @property string $scope
 * @property string $reason
 * @property string $authorized_by
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoke_reason
 */
final class CustomerCreditOverride extends BaseEnterpriseModel
{
    public const string ScopeAll = 'all';

    public const string ScopeOrder = 'order';

    public const string ScopeDeliveryNote = 'delivery_note';

    public const array Scopes = [self::ScopeAll, self::ScopeOrder, self::ScopeDeliveryNote];

    protected $table = 'customer_credit_overrides';

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Overrides that still allow what they cover at `$now`.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query, CarbonInterface $now): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(static fn (Builder $inner) => $inner->whereNull('expires_at')->orWhere('expires_at', '>', $now));
    }

    public function covers(string $scope): bool
    {
        return $this->scope === self::ScopeAll || $this->scope === $scope;
    }
}
