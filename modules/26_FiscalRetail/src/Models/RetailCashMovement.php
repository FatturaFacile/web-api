<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Models;

use App\Models\PosSession;
use Database\Factories\RetailCashMovementFactory;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pay-in, pay-out or safe drop of the cash drawer of a POS session.
 */
#[UseFactory(RetailCashMovementFactory::class)]
final class RetailCashMovement extends Model
{
    /** @use HasFactory<RetailCashMovementFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'retail_cash_movements';

    protected $fillable = ['tenant_id', 'pos_session_id', 'type', 'amount', 'reason', 'user_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public const array Types = ['pay_in', 'pay_out', 'safe_drop'];

    /**
     * @return BelongsTo<PosSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }
}
