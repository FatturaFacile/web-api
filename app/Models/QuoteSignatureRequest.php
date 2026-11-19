<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A time-limited tokenized link through which a customer reviews, signs or asks changes to a quote. Only the SHA-256 of
 * the token is stored.
 */
class QuoteSignatureRequest extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    public const string Pending = 'pending';

    public const string Signed = 'signed';

    public const string ChangesRequested = 'changes_requested';

    public const string Revoked = 'revoked';

    protected $fillable = [
        'tenant_id',
        'quote_id',
        'token_hash',
        'locale',
        'expires_at',
        'revoked_at',
        'status',
        'signer_name',
        'signer_role',
        'purchase_order_number',
        'signature_type',
        'signature_data',
        'selected_optional_item_ids',
        'change_request',
        'ip_address',
        'user_agent',
        'payload_hash',
        'pdf_hash',
        'signed_at',
        'created_by',
    ];

    protected $hidden = ['token_hash', 'signature_data'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'signed_at' => 'datetime',
            'selected_optional_item_ids' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
