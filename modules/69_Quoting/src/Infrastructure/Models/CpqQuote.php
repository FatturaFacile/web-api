<?php

declare(strict_types=1);

namespace Modules\Quoting\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Quoting\Domain\Enums\ConversionTarget;
use Modules\Quoting\Domain\Enums\QuoteStatus;
use Modules\Quoting\Domain\Enums\SignatureLevel;
use Modules\Quoting\Domain\Exceptions\ImmutableQuoteRevisionException;

/**
 * One revision of a CPQ quote. A revision that left the draft states is frozen: its commercial columns can no longer
 * change (ImmutableQuoteRevisionException); only the lifecycle columns listed in LifecycleColumns still move.
 *
 * @property string $id
 * @property string|null $root_quote_id
 * @property string|null $parent_quote_id
 * @property string $quote_number
 * @property int $revision
 * @property QuoteStatus $status
 * @property string $jurisdiction
 * @property string $currency
 * @property SignatureLevel $signature_level
 * @property ConversionTarget|null $auto_convert_target
 * @property array<string, mixed>|null $frozen_snapshot
 * @property array<string, bool>|null $customer_selections
 * @property Carbon|null $valid_until
 * @property Carbon|null $public_expires_at
 * @property Carbon|null $issued_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $viewed_at
 * @property Carbon|null $rejected_at
 * @property string|null $public_token_hash
 * @property string|null $public_pin_hash
 * @property string|null $customer_email
 * @property string|null $customer_name
 * @property string|null $converted_sales_order_id
 * @property string|null $converted_invoice_id
 * @property bool $auto_convert
 * @property bool $requires_approval
 */
final class CpqQuote extends BaseEnterpriseModel
{
    protected $table = 'cpq_quotes';

    /** Columns that still move on a frozen revision. */
    private const array LifecycleColumns = [
        'status', 'viewed_at', 'accepted_at', 'rejected_at', 'rejection_reason', 'customer_selections', 'signed_document_path',
        'signed_document_hash', 'audit_certificate_path', 'audit_certificate_hash', 'converted_sales_order_id', 'converted_invoice_id',
        'public_token_hash', 'public_pin_hash', 'public_expires_at', 'total_net', 'total_tax', 'total_gross', 'total_cost', 'margin_percent',
        'updated_at', 'updated_by', 'deleted_at', 'deleted_by',
    ];

    protected static function booted(): void
    {
        self::updating(static function (self $quote): void {
            $original = $quote->getRawOriginal('status');
            $wasFrozen = is_string($original) && QuoteStatus::from($original)->isFrozen();

            if (! $wasFrozen) {
                return;
            }

            $illegal = array_diff(array_keys($quote->getDirty()), self::LifecycleColumns);
            if ($illegal !== []) {
                throw ImmutableQuoteRevisionException::forQuote($quote->quote_number);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'signature_level' => SignatureLevel::class,
            'auto_convert_target' => ConversionTarget::class,
            'billing_address' => 'array',
            'shipping_address' => 'array',
            'frozen_snapshot' => 'array',
            'customer_selections' => 'array',
            'valid_until' => 'date',
            'public_expires_at' => 'datetime',
            'issued_at' => 'datetime',
            'viewed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'requires_approval' => 'boolean',
            'auto_convert' => 'boolean',
            'revision' => 'integer',
            'exchange_rate' => 'decimal:8',
            'header_discount_percent' => 'decimal:4',
            'min_margin_percent' => 'decimal:4',
            'total_net' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'total_gross' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'margin_percent' => 'decimal:2',
            'number_year' => 'integer',
            'number_sequence' => 'integer',
        ];
    }

    /**
     * @return HasMany<CpqQuoteSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(CpqQuoteSection::class, 'quote_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<CpqQuoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CpqQuoteLine::class, 'quote_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<CpqSignatureEnvelope, $this>
     */
    public function envelopes(): HasMany
    {
        return $this->hasMany(CpqSignatureEnvelope::class, 'quote_id');
    }

    /**
     * @return HasMany<CpqAuditEvent, $this>
     */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(CpqAuditEvent::class, 'quote_id')->orderBy('sequence');
    }

    /**
     * @return HasMany<CpqComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(CpqComment::class, 'quote_id')->orderBy('created_at');
    }

    /**
     * @return BelongsTo<CpqQuote, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_quote_id');
    }

    public function isFrozen(): bool
    {
        return $this->status->isFrozen();
    }

    public function assertEditable(): void
    {
        if ($this->isFrozen()) {
            throw ImmutableQuoteRevisionException::forQuote($this->quote_number);
        }
    }
}
